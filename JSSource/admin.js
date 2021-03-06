function cms_edit_content(container){
    $('#content_block_'+container).hide();
    $('#edit_content_block_'+container).show();
}

function cms_save_content(container){
    $.ajax({
        url:'/cms.php',
        data:{action:'update_content','container':container,content:CKEDITOR.instances["cke_"+container].getData()},
        type:"POST",
        dataType:"html",
        success:function(){
            $('#content_body_'+container).html(CKEDITOR.instances["cke_"+container].getData());
            $('#content_block_'+container).show();
            $('#edit_content_block_'+container).hide();
        }
    });
}
lightning.admin = {};
/**
 * Functions for editing mailing messages.
 *
 * @type {{checkVars: Function}}
 */
lightning.admin.messageEditor = {
    checkVars: function() {
        var selected_items = $('#message_criteria_input_array').val();
        $.ajax({
            url:'/admin/mailing/messages',
            data:{
                action: 'fields',
                criteria_list: selected_items,
                message_id: $('#id').val()
            },
            dataType:'JSON',
            type:'GET',
            success:function(data){
                selected_items = selected_items.split(',');
                // Iterate over active criteria.
                for(var i=0; i< selected_items.length; i++) {
                    if (selected_items[i] != '') {
                        // Iterate over returned criteria.
                        for (var j in data.criteria) {
                            var criteria_id = data.criteria[j].criteria_id;
                            // If this is the matching criteria id.
                            if (data.criteria[j].criteria_id == selected_items[i]) {
                                // Iterate over variables.
                                for (var k in data.criteria[j].variables) {
                                    // If the field is not already present.
                                    var variable = data.criteria[j].variables[k];
                                    if ($('#var_' + criteria_id + '_' + variable).length == 0) {
                                        var value = (data.criteria[j].values && data.criteria[j].values[variable]) ? data.criteria[j].values[variable] : '';
                                        $('#message_criteria_box_' + selected_items[i])
                                            .append('<div id="var_' + criteria_id + '_' + variable + '" >' + variable + ': <input type="text" name="var_' + criteria_id + '_' + variable + '" value="' + value + '"></div>');
                                    }
                                }
                            }
                        }
                    }
                }
            },
            error:function(){
                alert('error');
            }
        });
    }
};
lightning.admin.messages = {
    send: function(type) {
        // Start AJAX transmission.
        $('#message_status').html('Starting ...\n');
        $('.mail_buttons').fadeOut();
        var last_response_len = 0;
        var self = this;
        $.ajax({
            url: '/admin/mailing/send?action=send-' + type + '&id=' + lightning.vars.message_id,
            dataType: 'text',
            data: {token: lightning.vars.token},
            type: 'POST',
            stream: true,
            xhrFields: {
                onprogress: function(e) {
                    var response = e.currentTarget.response;
                    self.addContent('#message_status', response.substring(last_response_len));
                    last_response_len = response.length;
                }
            },
            success: function(data) {
                self.addContent('#message_status', data.substring(last_response_len));
                $('.mail_buttons').fadeIn();
            },
            error: function() {
                $('.mail_buttons').fadeIn();
            }
        });
    },
    addContent: function (container, content) {
        $container = $(container);
        $container.html($container.html() + content);
        $container.animate({ scrollTop: $container.attr("scrollHeight") }, 500);
    }
};
