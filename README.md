# SuperXSS
## Make XSS Great Again
> * 当X别人站的时候，遇到Httponly flag时，被x到的站位于内网无法直接访问时，你要做的是：
> * 换下一个站 X
> * 用SuperXSS √

SuperXSS是一个基于Websocket的客户端网页代理程序，客户端JS被注入之后会创建到指定服务端的Websocket连接并接收命令进行XHR请求，从而使得无法直接访问的后台等可以通过客户端浏览器本身作为代理访问。
程序本身分为两部分，前端JS部分感谢@[https://github.com/Archeb](Archeb)，后端本人使用Workerman瞎写的代码。
程序本身并不稳定，不过至少能够操作一下后台。

### 使用
更改服务端Config.php中REPLACE_ADDR为中转服务端的HTTP(S)访问地址，HIJACK_CONSOLE_LISTEN和WS_LISTEN分别改为劫持控制台的监听地址和Websocket的监听地址，如需要WSS可以配合Nginx做反代使用。
更改xss.js中最后一行的地址为中转服务端Websocket的监听地址
将xss.js插入到页面中
访问劫持控制台，此时应该能看到劫持会话选项。
劫持会话之后，你应该能直接操作或者直接更改URL来访问其他同域名地址，或者带着Cookie直接扔进sqlmap等工具。

### DEMO
插入到目标页面之中
![受害者.jpg][1]
访问劫持控制台
![说了我不会前端.jpg][2]
劫持会话，完 全 一 致
![大 胜 利][3]
同时可以访问同域下的其他内容
![24岁，是学生][4]


  [1]: https://static.moe.do/Uploads/image/20190407/1.png
  [2]: https://static.moe.do/Uploads/image/20190407/2.png
  [3]: https://static.moe.do/Uploads/image/20190407/3.png
  [4]: https://static.moe.do/Uploads/image/20190407/4.png
