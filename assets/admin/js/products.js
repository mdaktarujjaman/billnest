(function ($) {
    $(function () {
        var frame;
        var $input = $('#image_url');
        var $preview = $('#billnest-image-preview');
        var $remove = $('#billnest-remove-image');

        function render(url) {
            if (url) {
                $preview.empty().append($('<img>', { src: url, alt: '' })).show();
                $remove.show();
            } else {
                $preview.empty().hide();
                $remove.hide();
            }
        }

        $('#billnest-select-image').on('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Select product image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.url);
                render(attachment.url);
            });

            frame.open();
        });

        $remove.on('click', function (event) {
            event.preventDefault();
            $input.val('');
            render('');
        });

        render($input.val());
    });
})(jQuery);