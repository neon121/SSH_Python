var socket;
var pingInterval;
var uploadField;
var intervals = [];
$(function() {
    if (window.location.protocol == 'https:')
        socket = new WebSocket('wss://' + window.location.host + ':15004');
    else socket = new WebSocket('ws://' + window.location.host + ':15004');
    socket.onopen = function() {
        addLog('Daemon connected');
        $('#status .daemon').addClass('connected');
        pingInterval = setInterval(function() {
            socket.send(JSON.stringify({action: 'ping'}))
        }, 100000000); //todo change time limit
    };
    socket.onmessage = function(message) {
        var data = JSON.parse(message.data);
        console.log(data);
        switch (data.action) {
            case 'load':
                var ses = data.SESSION;
                $('input[name=host]').val(ses.host);
                $('input[name=username]').val(ses.username);
                $('input[name=password]').val(ses.password);
                $('input[name=secondFactor][value="' + ses.secondFactor + '"]').prop('checked', true);
                $('[name=dir]').val(ses.dir);
                $('.dir').text(ses.dir);
                var etalon;
                etalon = $('.interpreter.etalon');
                for (let id in ses.interpreters) {
                    let inter = ses.interpreters[id];
                    let div = etalon.clone(true).removeClass('etalon');
                    div.attr('data-prev', inter).find('input').val(inter);
                    div.appendTo($('.interpreters'));
                    let option = $('<option/>');
                    option.attr('value', inter).text(inter)
                        .appendTo($('#ssh .command.etalon [name=interpreter]'));
                }
                etalon.clone(true).removeClass('etalon').attr('data-new', 'true').appendTo($('.interpreters'));
                etalon = $('.command.etalon');
                for (let id in ses.commands) {
                    let command = ses.commands[id];
                    let div = etalon.clone(true).removeClass('etalon');
                    div.attr('data-id', id)
                        .find('[name=interpreter] [value=' + command.interpreter + ']').prop('selected', true);
                    div.find('[name=arguments]').val(command.arguments);
                    div.find('[name=file]').attr('data-value', command.file);
                    div.appendTo($('#ssh .commands'));
                }
                etalon.clone(true).removeClass('etalon').attr('data-new', 'true').appendTo($('.commands'));
                break;
            case 'DuoAuth':
                var secondFactor = $('#login [name=secondFactor]:checked').val();
                if (secondFactor == "SMS passcodes") $('#login .DuoAnswer').addClass('visible');
                addLog('Waiting for 2nd factor...');
                break;
            case 'connected':
                $('#login .DuoAnswer').removeClass('visible');
                $('#status .SSH').addClass('connected');
                $('#options, #ssh').addClass('visible');
                addLog('SSH connected');
                break
            case 'disconnected':
                $('#login .DuoAnswer').removeClass('visible');
                $('#status .SSH').removeClass('connected');
                $('#options, #ssh').removeClass('visible');
                $('#login .loginForm button.login').attr('disabled', 'true');
                addLog('SSH disconnected');
                break;
            case 'dir':
                $('#options .files .file:not(.etalon)').remove();
                var etalon = $('#options .files .file.etalon');
                var selects = $('#ssh .command:not(.etalon) [name=file]');
                selects.each(function() {
                    if ($(this).val()) $(this).attr('data-value', $(this).val());
                    $(this).find('option:not(:disabled)').remove();
                });
                for (let i in data.files) {
                    let div = etalon.clone(true).removeClass('etalon');
                    div.attr('data-prev', data.files[i])
                        .find('input').val(data.files[i]);
                    div.appendTo($('#options .files'));
                    let option = $('<option/>');
                    option.attr('value', data.files[i]).text(data.files[i]).appendTo(selects);
                }
                selects.each(function() {
                    if ($(this).attr('data-value')) {
                        $(this).find('option:contains(' + $(this).attr('data-value') + ')').prop('selected', true);
                        $(this).attr('data-value', null);
                    }
                });
                break;
            case 'command':
                var div = $('#ssh .command[data-id='+data['id']+']');
                switch (data['status']) {
                    case 'running':
                        div.removeClass('pending done').addClass('running');
                        div.start = new Date();
                        div.find('.time').text(0);
                        intervals[data['id']] = setInterval(function(id, start) {
                            var dif  = Math.round(((new Date) - start) / 1000);
                            $('#ssh .command[data-id='+id+'] .time').text(dif);
                        }, 1000, data['id'], new Date());
                        break;
                    case 'done':
                        div.removeClass('pending running').addClass('done');
                        clearInterval(intervals[data['id']]);
                        break;
                }
                break;
            case 'view':
                var view = $('#view');
                view.find('.title').text(data.title);
                view.find('.text').text(atob(data.text));
                view.addClass('visible');
                break;
            case 'log':
                addLog(data.text, data.type);
                break;
            case 'pong':
                if (data.isConnected == false && $('#status .SSH').hasClass('connected')) {
                    addLog('SSH connection is lost', 'error');
                    $('#status .SSH').removeClass('connected');
                }
                if (data.isConnected) $('#login .loginForm button.login').attr('disabled', 'true');
                else $('#login .loginForm button.login').attr('disabled', null);
                break;
            case 'debug':
                console.log(data.result);
                break;
        }
    };
    socket.onclose = function() {
        addLog('Daemon disconnected', 'error');
        $('#status .daemon').removeClass('connected');
        $('#status .SSH').removeClass('connected');
        clearInterval(pingInterval);
    };
    socket.onerror = function() {
        addLog('Daemon error, see console', 'error');
        $('#status .daemon').removeClass('connected');
        clearInterval(pingInterval);
    };

    $('button').mousedown(function() {$(this).addClass('mouseDown')})
               .mouseup(function() {$(this).removeClass('mouseDown')})
               .mouseout(function() {$(this).removeClass('mouseDown')})
               .attr('unselectable', 'on').addClass('unselectable');
    $('a.delete').click(function(e) {e.preventDefault();});

    $('#login .loginForm button.login').click(function() {
        if ($('#status .daemon.connected').length == 0) {
            addLog('Need daemon connection to SSH login', 'error');
            return;
        }
        $(this).attr('disabled', true);
        var data = {
            action: 'connect',
            host: $('#login [name=host]').val(),
            username: $('#login [name=username]').val(),
            password: $('#login [name=password]').val(),
            secondFactor: $('#login [name=secondFactor]:checked').val()
        };
        for (i in data) {
            if (data[i] == '') {
                alert ('All field are required');
                return false;
            }
        }
        addLog('Logging in...');
        socket.send(JSON.stringify(data));
    });
    $('#login .DuoAnswer button').click(function() {
        addLog('Sending answer...');
        var DuoAnswer = $('#login [name=DuoAnswer]').val();
        if (DuoAnswer == '') {
            alert ('Need Answer');
            return false;
        }
        socket.send(JSON.stringify({
            action: 'DuoAuth',
            DuoAnswer: DuoAnswer
        }));
    });
    $('#options .misc [name=dir]').change(function() {
        socket.send(JSON.stringify({action: 'option', name: 'dir', value: $(this).val()}));
    });
    $('#options .interpreters .interpreter input').change(function() {
        var div = $(this).parents('.interpreter');
        var data = {action: 'interpreter'};
        if (div.attr('data-new') != undefined) {
            data.subaction = 'add';
            data.interpreter = $(this).val();
            div.attr({
                'data-new': null,
                'data-prev': $(this).val()
            });
            $('#options .interpreter.etalon').clone(true)
                .attr('data-new', 'true').removeClass('etalon').appendTo($('#options .interpreters'));
            var option = $('<option/>');
            option.attr('value', $(this).val()).text($(this).val()).appendTo($('#ssh .commands [name=interpreter]'));
        }
        else {
            data.subaction = 'change';
            data.prev = div.attr('data-prev');
            data.interpreter = $(this).val();
            div.attr('data-prev', $(this).val());
            var options = $('#ssh .commands [name=interpreter] option[value='+data.prev+']');
            options.val(data.interpreter).text(data.interpreter);
        }
        socket.send(JSON.stringify(data));
    });
    $('#options .interpreters .interpreter .delete').click(function() {
        var div = $(this).parents('.interpreter');
        if (div.attr('data-new')) return;
        var name = div.find('input').val();
        if (confirm('Delete interpreter '+name+'?')) {
            socket.send(JSON.stringify({
                action: 'interpreter',
                subaction: 'delete',
                interpreter: name
            }));
            $(this).parents('.interpreter').remove();
            $('#ssh .command [name=interpreter] option[value="'+name+'"]:selected').parent()
                .find(':disabled').prop('selected', true);
            $('#ssh .command [name=interpreter] option[value="'+name+'"]').remove();
        }
    });
    $('#options .misc button.refreshDir').click(function() {
        $(this).addClass('pending');
        socket.send(JSON.stringify({action: 'file', subaction: 'dir'}))
    });
    $('#options .upload').click(function() {
        $('#options .uploadFiles').addClass('visible');
    });
    $('#options .files .file .view').click(function(e) {
        e.preventDefault();
        socket.send(JSON.stringify({
            action: 'file',
            subaction: 'view',
            name: $(this).parents('.file').find('input').val()
        }));
    })
    $('#options .files .file input').change(function() {
        var name = $(this).val();
        var prev = $(this).parents('.file').attr('data-prev');
        socket.send(JSON.stringify({
            action: 'file',
            subaction: 'change',
            prev: prev,
            name: $(this).val()
        }));
        $('#ssh .command [name=file] option[value="'+prev+'"]').text(name).attr('value', name);
        $(this).parents('.file').attr('data-prev', name);
    });
    $('#options .files .delete').click(function() {
        var name = $(this).parents('.file').find('input').val();
        if (confirm ('Delete file ' + name + '?')) {
            socket.send(JSON.stringify({
                action: 'file',
                subaction: 'delete',
                name: name
            }));
            $('#ssh .command [name=file] option[value="' + name + '"]:selected').parent()
                .find(':disabled').prop('selected', true);
            $('#ssh .command [name=file] option[value="' + name + '"]').remove();
            $(this).parents('.file').remove();
        }
    });

    Dropzone.options.uploadField = {
        paramName: 'files',
        url: 'not_a_url.php',
        uploadMultiple: true,
        autoProcessQueue: false,
        addRemoveLinks: true,
        dictRemoveFile: 'X'
    };
    uploadField = new Dropzone('#uploadField');
    $('#options .uploadFiles .delete').click(function() {
        $('#options .uploadFiles').removeClass('visible');
        uploadField.removeAllFiles(true);
    });
    $('#options .uploadFiles button').click(function() {
        for (var i in uploadField.files) {
            let file = uploadField.files[i];
            let fr = new FileReader();
            fr.addEventListener("loadend", function() {
                socket.send(JSON.stringify({
                    action: 'file',
                    subaction: 'upload',
                    name: this.name,
                    content: btoa(encodeURIComponent(this.result))
                }));
            });
            fr.name = file.name;
            fr.readAsText(file);
        }
        $('#options .uploadFiles .delete').click();
    });
    $('#ssh .commands .command').find('input, select').change(function() {
        var div = $(this).parents('.command');
        var data = {action: 'command'}
        if (div.attr('data-new') == 'true') {
            data.subaction = 'add';
            data.id = $('#ssh .commands .command').eq(-2).attr('data-id') || 0;
            div.attr({
                'data-id': data.id,
                'data-new': null
            });
            $('#ssh .command.etalon').clone(true)
                .attr('data-new', 'true').removeClass('etalon').appendTo($('#ssh .commands'));
        }
        else {
            data.subaction = 'change';
            data.id = div.attr('data-id');
        }
        data.name = $(this).attr('name');
        data.value = $(this).val();
        console.log(data);
        socket.send(JSON.stringify(data));
    });
    $('#ssh .commands .command button').click(function() {
        var command = $(this).parents('.command');
        if ($('#ssh').find('.status.pending, .status.running').length > 0) return;
        if ($('#status .SSH.connected').length == 0) {
            addLog('Need SSH connection to run command', 'error');
            return;
        }
        socket.send(JSON.stringify({
            action: 'command',
            subaction: 'run',
            id: command.attr('data-id')
        }));
        command.removeClass('done').addClass('pending');
    });
    $('#ssh .commands .command .delete').click(function() {
        var command = $(this).parents('.command');
        if (command.attr('data-new')) return;
        if (command.hasClass('pending') || command.hasClass('running')) return;
        if (confirm ('Delete command?')) {
            socket.send(JSON.stringify({
                action: 'command',
                subaction: 'delete',
                id: command.attr('data-id')
            }));
            command.remove();
        }
    });
    $('#ssh .commands .command .stop').click(function(e) {
        e.preventDefault();
        var command = $(this).parents('.command');
        $.post('ajax.php', {action: 'stopFlag'}, function(data) {
            console.log(data);
        })
        /*socket.send(JSON.stringify({
            action: 'command',
            subaction: 'stop',
            id: command.attr('data-id')
        }));*/
    });
    $('#ssh .commands .command .output').click(function(e) {
        e.preventDefault();
        var command = $(this).parents('.command');
        socket.send(JSON.stringify({
            action: 'command',
            subaction: 'output',
            id: command.attr('data-id')
        }));
    });
    $('#view .delete').click(function() {$('#view').removeClass('visible');});
});

function addLog(text, type) {
    var p = $('<p/>');
    p.text((new Date()).toLocaleTimeString() + ' ' + text).addClass(type);
    $('#login .log').append(p);
}

function d(str) {socket.send(JSON.stringify({action: 'debug', code: str}));}