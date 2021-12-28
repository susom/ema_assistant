
function updateWindow() {
    let r = $('#records').val();

    // Get all windows
    $('#spinner').show();
    $.ajax({
        "method": "POST",
        data: {
            "action": "getWindows",
            "record": r
        }
    })
        .done(function (d) {
            let windows = $('#windows');
            windows.empty();
            for (const w of d) {
                let opt = $('<option value="' + w.name + '">' + w.name + ' (' + w.incomplete + '/' + w.count + ' incomplete)</option>')
                    .appendTo(windows);
            }
            // console.log("Done", d);

            $('#remove').show();

            $('#spinner').hide();
        })
}

function updateRecords() {
    // Get all records
    $("#spinner").show();
    $.ajax({
        "method": "POST",
        data: {action: "getRecords"}
    })
        .done(function (d) {
            let records = $('#records');
            records.empty();
            for (const r of d) {
                let opt = $('<option value="' + r + '">' + r + '</option>').appendTo(records);
            }
            // console.log("Done", d);

            $('#spinner').hide();
            records.trigger('change');

        })
}

function removeEntries() {
    $('#spinner').show();

    const record = $('#records').val();
    const window = $('#windows').val();

    if (confirm("Are you sure you want to delete all incomplete instances of " + window + " for record " + record)) {
        $.ajax({
            "method": "POST",
            data: {
                action: "deleteInstances",
                record: record,
                window: window
            }
        })
            .done(function (d) {
                $('#spinner').hide();
                if (d.hasOwnProperty('error')) {
                    alert(d.error);
                }
                updateWindow();
            })


    }
}


$(document).ready(function() {

    $('#records').bind('change',updateWindow);

    $('#remove').bind('click', removeEntries);

    updateRecords();
});
