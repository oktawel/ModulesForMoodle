<?php

defined('MOODLE_INTERNAL') || die();

function local_plagiarismchecker_before_http_headers() {

    global $PAGE;

    if (CLI_SCRIPT || AJAX_SCRIPT) {
        return;
    }

    $url = $PAGE->url->out(false);
    // echo "<script>console.log('URL: " . htmlspecialchars($url) . "');</script>";
    if (strpos($url, '/mod/assign/view.php') === false) {
        return;
    }

    global $DB;

    $cmid = optional_param('id', 0, PARAM_INT);
    $userid = optional_param('userid', 0, PARAM_INT);

    $showplagiarism = false;

    if ($cmid) {
        $cm = get_coursemodule_from_id('assign', $cmid);
        if ($cm) {
            $assign = $DB->get_record('assign', ['id' => $cm->instance]);
            $showplagiarism = !empty($assign->showplagiarism);
        }
    }
    $PAGE->requires->js_init_code("
        window.showPlagiarism = " . json_encode($showplagiarism) . ";
    ");

    $context = context_module::instance($cm->id);

    $canview = has_capability('mod/assign:grade', $context);

    $showbutton = $canview || !empty($assign->showplagiarism);

    $js = <<<JS
document.addEventListener('DOMContentLoaded', function () {

    if (!$showbutton) {
        return;
    }
    // if (!window.showPlagiarism) {
    //     return;
    // }

    // if (document.getElementById('plagiarismchecker-btn')) {
    //     return;
    // }

    // ====== КНОПКА ПРОВЕРКИ ======
    const btn = document.createElement('button');
    btn.id = 'plagiarismchecker-btn';
    btn.innerText = 'Проверить на схожесть';
    btn.className = 'btn btn-primary';

    btn.style.position = 'fixed';
    btn.style.bottom = '20px';
    btn.style.right = '20px';
    btn.style.zIndex = '99999';

    document.body.appendChild(btn);

    // ====== КНОПКА СКРЫТИЯ (СНАЧАЛА СКРЫТА) ======
    const hideBtn = document.createElement('button');
    hideBtn.innerText = 'Скрыть результаты';
    hideBtn.className = 'btn btn-secondary';

    hideBtn.style.position = 'fixed';
    hideBtn.style.bottom = '20px';
    hideBtn.style.right = '220px';
    hideBtn.style.zIndex = '99999';
    hideBtn.style.display = 'none';

    document.body.appendChild(hideBtn);


    // ====== БЛОК РЕЗУЛЬТАТОВ ======
    const resultBox = document.createElement('div');
    resultBox.id = 'plagiarismchecker-results';

    resultBox.style.position = 'fixed';
    resultBox.style.bottom = '80px';
    resultBox.style.right = '20px';
    resultBox.style.width = '75vw';
    resultBox.style.maxHeight = '70vh';
    resultBox.style.overflow = 'auto';
    resultBox.style.background = 'white';
    resultBox.style.border = '1px solid #ccc';
    resultBox.style.padding = '10px';
    resultBox.style.zIndex = '99999';
    resultBox.style.display = 'none';

    document.body.appendChild(resultBox);

    // ====== ПОКАЗ/СКРЫТИЕ ======
    hideBtn.addEventListener('click', function () {
        if (resultBox.style.display === 'none') {
            resultBox.style.display = 'block';
            hideBtn.innerText = 'Скрыть результаты';
        } else {
            resultBox.style.display = 'none';
            hideBtn.innerText = 'Показать результаты';
        }
    });

    // ====== ПОДСВЕТКА ======
    function applyHighlight() {
        const threshold = parseFloat(document.getElementById('plag-threshold').value || 0);

        document.querySelectorAll('#plagiarismchecker-results tr').forEach((row, i) => {
            if (i === 0) return; // header

            const cell = row.children[3]; // %
            if (!cell) return;

            const value = parseFloat(cell.innerText.replace('%', ''));

            if (value >= threshold) {
                row.style.background = '#ffcccc';
            } else {
                row.style.background = '';
            }
        });
    }

    function resetHighlight() {
        document.querySelectorAll('#plagiarismchecker-results tr').forEach(row => {
            row.style.background = '';
        });
    }

    document.addEventListener('click', function(e) {

        if (e.target && e.target.id === 'plag-highlight') {
            applyHighlight();
        }

        if (e.target && e.target.id === 'plag-reset') {
            resetHighlight();
        }
    });

    // ====== ПРОВЕРКА ======
    btn.addEventListener('click', function () {

        resultBox.style.display = 'block';
        hideBtn.style.display = 'block';

        resultBox.innerHTML = 'Выполняется проверка...';

        const params = new URLSearchParams(window.location.search);

        const assignid = params.get('id');
        const userid = params.get('userid');

        fetch(M.cfg.wwwroot + '/local/plagiarismchecker/check.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'assignid=' + assignid + '&userid=' + userid
        })
        .then(r => r.json())
        .then(data => {

            if (!data || data.length === 0) {
                resultBox.innerHTML = '<b>Совпадений не обнаружено</b>';
                return;
            }

            let html = '<b>Результаты сравнения</b><br><br>';

            html += `
            <div style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
                <input id="plag-threshold" type="number" min="0" max="100" value="50"
                    style="width:80px;"> %

                <button id="plag-highlight" class="btn btn-danger btn-sm">
                    Подсветить
                </button>

                <button id="plag-reset" class="btn btn-secondary btn-sm">
                    Сброс
                </button>
            </div>
            `;

            html += '<table style="width:100%;border-collapse:collapse;font-size:14px">';
            html += '<tr>';
            html += '<th>Файл текущего студента</th>';
            html += '<th>Студент</th>';
            html += '<th>Файл проверки</th>';
            html += '<th>%</th>';
            html += '</tr>';

            data.forEach(item => {
                html += '<tr>';
                html += '<td>' + item.sourcefile + '</td>';
                html += '<td>' + item.studentname + '</td>';
                html += '<td>' + item.comparefile + '</td>';
                html += '<td>' + item.similarity + '%</td>';
                html += '</tr>';
            });

            html += '</table>';

            resultBox.innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            resultBox.innerHTML = 'Ошибка проверки';
        });

    });

});

JS;
$PAGE->requires->js_init_code($js);
}