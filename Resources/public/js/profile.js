$(function() {
    function checkRoleInputs() {
        inputs = $('#roles-list .controls').find(':checkbox');
        inputs.attr('required', inputs.filter(':checked').length > 0 ? null : 'required');
    }

    $(document).on('click', '#btn-apigen', function (e) {
        el = $(this);

        $.get(el.attr('href'), function (data) {
            el.prev().text(data);
        })

        return false;
    });

    $(document).on('click', '#roles-list input', function (e) {
        checkRoleInputs();
    });

    $(document).on('click', '#btn-remove-profile', function (e) {
        var el = $(this),
            message = el.attr('data-message'),
            doAction = function() {
                $.ajax({
                    url: Routing.generate('oro_api_delete_profile', { id: el.attr('data-id') }),
                    type: 'DELETE',
                    success: function (data) {
                        if (OroApp.hashNavigationEnabled()) {
                            OroApp.Navigation.prototype.setLocation(Routing.generate('oro_user_index'))
                        } else {
                            window.location.href = Routing.generate('oro_user_index');
                        }
                    }
                });
            };

        if (!_.isUndefined(Oro.BootstrapModal)) {
            confirm = new Oro.BootstrapModal({
                title: 'Delete Confirmation',
                content: message,
                okText: 'Yes, Delete'
            });
            confirm.on('ok', doAction);
            confirm.open();
        } else if (window.confirm(message)) {
            doAction();
        }

        return false;
    });

    /**
     * Process role checkboxes after hash navigation request is completed
     */
    OroApp.Events.bind(
        "hash_navigation_request:complete",
        function () {
            checkRoleInputs();
        },
        this
    );

    $(document).on('change', '#btn-enable input', function(e) {
        $('.status-enabled').toggleClass('hide');
        $('.status-disabled').toggleClass('hide');
    });
});