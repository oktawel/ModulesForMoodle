import Ajax from 'core/ajax';
import Templates from 'core/templates';

export const init = () => {

    const url = new URL(window.location.href);

    const action = url.searchParams.get('action');
    const userid = url.searchParams.get('userid');
    const cmid = url.searchParams.get('id');

    // только страница grader
    if (action !== 'grader') {
        return;
    }

    if (!userid || !cmid) {
        return;
    }

    // защита от дублей
    if (document.getElementById('plagiarismchecker-btn')) {
        return;
    }

    // создаём кнопку
    const btn = document.createElement('button');
    btn.id = 'plagiarismchecker-btn';
    btn.innerText = 'Проверить на схожесть';

    btn.style.position = 'fixed';
    btn.style.bottom = '20px';
    btn.style.right = '20px';
    btn.style.zIndex = '99999';
    btn.className = 'btn btn-primary';

    document.body.appendChild(btn);

    // контейнер результатов
    const resultBox = document.createElement('div');
    resultBox.id = 'plagiarismchecker-results';
    resultBox.style.position = 'fixed';
    resultBox.style.bottom = '70px';
    resultBox.style.right = '20px';
    resultBox.style.width = '400px';
    resultBox.style.maxHeight = '300px';
    resultBox.style.overflow = 'auto';
    resultBox.style.background = 'white';
    resultBox.style.border = '1px solid #ccc';
    resultBox.style.padding = '10px';
    resultBox.style.zIndex = '99999';

    document.body.appendChild(resultBox);

    // обработчик
    btn.addEventListener('click', () => {

        resultBox.innerHTML = 'Выполняется проверка...';

        Ajax.call([{
            methodname: 'local_plagiarismchecker_check',
            args: {
                cmid: parseInt(cmid),
                userid: parseInt(userid)
            }
        }])[0]
        .then(response => {

            if (!response || !response.results) {
                resultBox.innerHTML = 'Нет данных';
                return;
            }

            Templates.render(
                'local_plagiarismchecker/results',
                { results: response.results }
            )
            .then((html, js) => {
                Templates.replaceNodeContents(resultBox, html, js);
            });

        })
        .catch(error => {
            console.error(error);
            resultBox.innerHTML = 'Ошибка проверки';
        });
    });
};