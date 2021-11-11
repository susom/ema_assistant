if(typeof EM === 'undefined') { var EM = {}; }

// Inits an ace editor and uses the data-theme and data-mode attributes of the parent id
// to set the ace editor options
EM.initAceEditor = function(id) {

    // Create an ACE editor on the id element with mode mode
    var editorElement = $('#'+id);
    var currentValue = $(editorElement).html();
    var editor = ace.edit(id);

    // Set the theme
    var theme = editorElement.data('theme').length ? editorElement.data('theme') : "clouds";
    editor.setTheme("ace/theme/" + theme);

    // Set the mode
    var mode = editorElement.data('mode').length ? editorElement.data('mode') : "html";
    editor.getSession().setMode("ace/mode/" + mode);

    // Set the behavior
    editor.$blockScrolling = Infinity;
    editorElement.width('100%');

    // Bind the SAVE command
    editor.commands.addCommand({
        name: 'saveFile',
        bindKey: {
            win: 'Ctrl-S',
            mac: 'Command-S',
            sender: 'editor|cli'
        },
        exec: function(env, args, request) {
            EM.saveConfig();
        }
    });

    // For html, remove a few annotations that don't apply
    if (mode === 'html') {
        var session = editor.getSession();
        session.on("changeAnnotation", function () {
            var annotations = session.getAnnotations() || [], i = len = annotations.length;
            while (i--) {
                if (/doctype first\. Expected/.test(annotations[i].text)) {
                    annotations.splice(i, 1);
                }
                else if (/Unexpected End of file\. Expected/.test(annotations[i].text)) {
                    annotations.splice(i, 1);
                }
            }
            if (len > annotations.length) {
                session.setAnnotations(annotations);
            }
        });
    }

    // Set the default content
    // editor.setValue(EM.startVal,1);

    // Set focus to the editor
    editor.focus();

    // Cache the editor to the EM object for easier access
    EM.editor = {id: id, instance: editor, mode: mode};
}


// Try to set a reasonable size to the ACE editor based on size of the display/window
EM.resizeAceEditor = function () {
    var editor = $('#' + EM.editor.id);

    // Space in window
    var windowHeight = window.innerHeight;

    // Top of active editor (our y=0 point)
    var editorTop = editor.position().top;

    // Space for panel buttons
    var buttonSpace = 80;

    // pixels available (without footer)
    var windowSpace = windowHeight-editorTop-buttonSpace;

    // if footer is off page, use entire windowSpace.
    var h = max(200,windowSpace);

    // Apply height to all editors (even hidden ones)
    editor.css('height', h.toString() + 'px');
}


EM.saveConfig = function(callback) {
    var saveBtn = $('button[name="save"]');
    var saveBtnHtml = saveBtn.html();
    saveBtn.html('<img src="'+app_path_images+'progress_circle.gif"> Saving...');
    saveBtn.prop('disabled',true);

    // Prepare data to save
    var data = { "action": "save" };
    var editor = EM.editor.instance;
    data.raw_config = editor.getValue().toString();

    // Post back saved to config.php page
    var jqxhr = $.ajax({
        method: "POST",
        data: data,
        dataType: "json"
    })
        .done(function (data) {
            if (data.result === 'success') {
                // all is good
            } else {
                // an error occurred
                simpleDialog("Unable to Save<br><br>" + data.message, "ERROR - SAVE FAILURE" );
            }

            // Optionally you can pass a callback function (for save and quit, for example)
            if (callback) {
                callback(data);
                return false;
            }
        })
        .fail(function () {
            alert("error");
        })
        .always(function() {
            saveBtn.html(saveBtnHtml);
            saveBtn.prop('disabled',false);
        });
}


EM.closeEditor = function() {
    // go back to the table by making a get to the same url
    var url = window.location.href.replace(/\#$/, "");
    $(location).attr('href', url);
}


// A prepare the editor page by doing all necessary javascript add-ons
EM.initEditor = function() {

    // Create the ACE Editor
    $('div[data-editor="ace"]').each( function (i,e) {
        EM.initAceEditor($(e).attr('id'));
    });

    // Add a resize handler and call it once to optimize the current layout
    $(window).on('resize', function () {
        EM.resizeAceEditor();
    });

    // Bind the action buttons on the editor
    $('.config-editor-buttons button').on('click', function() {
        var action = $(this).attr('name');

        if (action === "save") {
            EM.saveConfig();
        }

        if (action === "beautify") {
            var editor = EM.editor.instance;
            var currentValue = editor.getValue();
            try {
                var json = JSON.parse(currentValue);
                editor.setValue(JSON.stringify(json, null, '\t'));
            }
            catch (e) {
                simpleDialog("Invalid JSON", "ERROR");
            }
        }

        if (action === "cancel") {
            EM.closeEditor();
        }
    });

    $(document).ready(function() {
        EM.resizeAceEditor();
    });
};


// Init
EM.initEditor();
