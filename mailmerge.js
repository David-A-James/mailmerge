/*
 * Copyright (c) 2023 bbecker
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

function mailmerge() {
    form = rcmail.gui_objects.messageform
    formData = new FormData()

    formData.append('_mode', rcmail.editor.is_html() ? "html" : "txt")
    formData.append('message', rcmail.editor.get_content())
    formData.append('_subject', $("[name='_subject']", form).val().trim())
    formData.append('_from', $("[name='_from']", form).val())

    formData.append('_to', $("[name='_to']", form).val())

    input_cc = $("[name='_cc']", form).val().trim().split(",").map(addr => addr.trim())
    formData.append('_cc', input_cc)

    input_bcc = $("[name='_bcc']", form).val().trim().split(",").map(addr => addr.trim())
    formData.append('_bcc', input_bcc)

    input_replyto = $("[name='_replyto']", form).val().trim().split(",").map(addr => addr.trim())
    formData.append('_replyto', input_replyto)

    input_followupto = $("[name='_followupto']", form).val().trim().split(",").map(addr => addr.trim())
    formData.append('_followupto', input_followupto)

    formData.append("_separator", $("#mailmergesep").val())
    formData.append("_enclosure",  $("#mailmergeencl").val())
    formData.append("_folder",  $("#mailmergefolder").val())

    let files = document.querySelector("#mailmergefile").files;
    if(files.length !== 0) {
        formData.append("csv", files[0], "data.csv")
    } else {
        rcmail.show_popup_dialog("No CSV File was selected!", "Error");
        return
    }

    compose_id = $("[name='_id']", form).val()
    formData.append("_compose_id", compose_id)

    formData.append("_mdn", document.querySelector("[name=_mdn]").checked);
    formData.append("_dsn", document.querySelector("[name=_dsn]").checked);
    formData.append("_priority", document.querySelector("[name=_priority]").value);

    // console.log(formData)

    const lock = rcmail.set_busy(true, 'loading');
    formData.append("_unlock", lock);
    formData.append("_remote", 1);

    $.ajax({
        type: 'POST', url: rcmail.url("plugin.mailmerge"), data: formData,
        dataType: "json",
        contentType: false,
        processData: false,
        success: function(data) { rcmail.http_response(data) },
        error: function(o, status, err) { rcmail.http_error(o, status, err, false, "plugin.mailmerge") }
    })
}

rcmail.addEventListener('init', function(evt) {
    console.log(evt)
    rcmail.register_command("plugin.mailmerge", mailmerge, true)
    rcmail.env.compose_commands.push("plugin.mailmerge")

    rcmail.http_get("plugin.mailmerge.get-folders")
});

rcmail.addEventListener("plugin.mailmerge.folders", function (data) {
    const drafts = data['special_folders']['drafts'] ?? "Drafts";
    $.each(data['folders'], function(i, folder) {
        $("#mailmergefolder").append($('<option>', {
            value: folder,
            text: folder,
            selected: folder === drafts
        }))
    })
})

rcmail.addEventListener("beforesavedraft", function (...params) {
    console.log(params)
})

rcmail.addEventListener("aftersavedraft", function (...params) {
    console.log(params)
})