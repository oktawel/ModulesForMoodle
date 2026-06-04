define(['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {

    var initialized = false;
    var globalButtonAdded = false;

    return {
        init: function (cmid) {
            if (initialized) {
                return;
            }
            initialized = true;

            this.attachIndividualHandlers();

            // Добавляем глобальную кнопку после загрузки страницы
            setTimeout(function () {
                this.addGlobalButton(cmid);
            }.bind(this), 500);
        },

        attachIndividualHandlers: function () {
            $(document).on('click', '.simcheck-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var btn = $(this);
                var originalText = btn.text();
                btn.prop('disabled', true).text('Проверка...');

                var requests = Ajax.call([{
                    methodname: 'plagiarism_simcheck_check',
                    args: {
                        cmid: btn.data('cmid'),
                        userid: btn.data('userid'),
                        submissionid: btn.data('submissionid')
                    }
                }]);

                requests[0].done(function (response) {
                    if (response.status === 'success') {
                        var colorClass = response.score >= 50 ? 'text-danger' : 'text-success';
                        var name = response.matched_username || 'Нет совпадений';
                        var html = '<span class="simcheck-result ' + colorClass + ' font-weight-bold ml-2">' +
                            'Схожесть: ' + response.score + '% (с ' + name + ')</span>';
                        btn.replaceWith(html);
                    }
                }).fail(function (ex) {
                    btn.prop('disabled', false).text(originalText);
                    Notification.exception(ex);
                });
            });
        },

        addGlobalButton: function (cmid) {
            if (globalButtonAdded) {
                return;
            }

            // Ищем таблицу оценивания
            var $table = $('.gradingtable').first();
            if ($table.length === 0) {
                return;
            }

            globalButtonAdded = true;

            // Создаем контейнер для кнопки
            var $container = $('<div class="simcheck-global-container mb-3"></div>');
            var $btn = $('<button class="btn btn-primary simcheck-global-btn">🔍 Проверить всех на плагиат</button>');

            $btn.on('click', function () {
                var btn = $(this);
                var originalText = btn.text();

                btn.prop('disabled', true).text('Проверка всех...');

                var checkButtons = $('.simcheck-btn');
                var total = checkButtons.length;

                if (total === 0) {
                    Notification.addNotification({
                        message: 'Нет работ для проверки',
                        type: 'info'
                    });
                    btn.prop('disabled', false).text(originalText);
                    return;
                }

                var processed = 0;

                function checkNext(index) {
                    if (index >= total) {
                        btn.text('✅ Проверка завершена!');
                        Notification.addNotification({
                            message: 'Проверено ' + total + ' работ',
                            type: 'success'
                        });
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                        return;
                    }

                    var currentBtn = $(checkButtons[index]);
                    btn.text('Проверка: ' + (index + 1) + ' из ' + total);

                    setTimeout(function () {
                        currentBtn.trigger('click');
                        setTimeout(function () {
                            checkNext(index + 1);
                        }, 1500);
                    }, 100);
                }

                checkNext(0);
            });

            $container.append($btn);
            $table.before($container);
        }
    };
});