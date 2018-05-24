<!DOCTYPE html>
<html lang="en">
<head>
    <title>SSH_Python</title>
    <script type="text/javascript" src="misc/jquery-3.3.1.min.js"></script>
    <script type="text/javascript" src="misc/dropzone.js"></script>
    <script type="text/javascript" src="misc/scripts.js"></script>
    <link rel="stylesheet" href="misc/styles.css"/>
</head>
<body>
<div id="status">
    <div class="daemon">
        Daemon:
        <span class="connected">connected</span>
        <span class="error">not connected</span>
    </div>
    <div class="SSH">
        SSH:
        <span class="connected">connected</span>
        <span class="error">not connected</span>
    </div>
</div>
<div id="login">
    <div class="loginForm">
        <div class="firstFactor">
            <input type="text" name="host" autocomplete="off" placeholder="host[:port]"/>
            <input type="text" name="username" placeholder="username"/>
            <input type="password" name="password" placeholder="password"/>
        </div>
        <div class="secondFactor">
            <label><input type="radio" name="secondFactor" value="SMS passcodes">SMS</label>
            <label><input type="radio" name="secondFactor" value="Phone call">Phone call</label>
            <label><input type="radio" name="secondFactor" value="Duo Push">Push message</label>
        </div>
        <button class="login">save&go</button>
        <div class="DuoAnswer">
            <div>
                    <input type="text" name="DuoAnswer" placeholder="Answer"/>
                <button>go</button>
            </div>
        </div>
    </div>
    <pre class="log"></pre>
</div>
<div id="options">
    <div class="options">
        <span class="title">Interpreters</span>
        <div class="interpreters">
            <div class="interpreter etalon">
                <input type="text"/>
                <a class="delete" href="">X</a>
            </div>
        </div>
        <div class="misc">
            <span class="title">Directory for files listing</span>
            <input type="text" name="dir"/>
            <button class="refreshDir">refresh files</button>
        </div>
        <span class="title">Directory listing</span>
        <button class="upload">Upload files</button>
        <div class="files">
            <div class="file etalon">
                <a class="view" href="" title="view">V</a>
                <input type="text" value="">
                <a class="delete" href="">X</a>
            </div>
        </div>
    </div>
    <div class="uploadFiles">
        <a href="" class="delete">X</a>
        <span class="title">↓ Drag&drop your files here to upload ↓</span>
        <span class="subtitle">or click on it to open download dialog</span>
        <div id="uploadField"></div>
        <span class="subtitle">
            Files with .tar extension will be perceived as archives and unpacked<br/>
            Directory structure will be ignored
        </span>
        <button>upload to <span class="dir"></span></button>
    </div>
</div>
<div id="ssh">
    <div class="commands">
        <div class="command etalon" data-id="">
            <select name="interpreter">
                <option value="" disabled selected>--interpreter--</option>
            </select>
            <input type="text" name="arguments" placeholder="arguments"/>
            <select name="file" data-value="">
                <option value="" disabled selected>--file--</option>
            </select>
            <button>run</button>
            <a class="delete" href="">X</a>
            <span class="status">
                <span class="pending">pending...</span>
                <span class="running">running: <span class="time"></span>s <a class="stop" href="">stop</a></span>
                <span class="done">completed <a class="output" href="">output</a></span>
                <span class="canceled">canceled <a class="output" href="">output</a></span>
            </span>
        </div>
    </div>
</div>
<div id="view">
    <a href="" class="delete">X</a>
    <span class="title"></span>
    <div class="text"></div>
</div>
</body>