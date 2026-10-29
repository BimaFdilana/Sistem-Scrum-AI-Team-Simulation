/* Renderer Markdown ringan khusus balasan AI di chat ScrumSpace.
 * Aman: semua teks di-escape dulu, baru pola Markdown diubah jadi HTML.
 * Hanya pola yang dipakai balasan AI: heading, tebal, miring, kode,
 * tautan http(s), daftar (termasuk bersarang + checklist), tabel,
 * garis pemisah, dan blok kode. Pesan pengguna TIDAK lewat renderer ini. */
(function () {
    'use strict';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function inline(text) {
        // Kode inline dilindungi dulu agar isinya tidak ikut diformat.
        var codes = [];
        var protectedText = String(text).replace(/`([^`]+)`/g, function (_, code) {
            codes.push('<code>' + code + '</code>');
            return '\u0000' + (codes.length - 1) + '\u0000';
        });
        var out = protectedText
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        return out.replace(/\u0000(\d+)\u0000/g, function (_, i) {
            return codes[Number(i)];
        });
    }

    function headingEmoji(plainText) {
        var text = plainText.toLowerCase();
        if (/^[^\w\s]/.test(plainText.trim())) return ''; // sudah ada emot/ikon
        if (text.indexOf('backlog') !== -1) return '📋 ';
        if (text.indexOf('user story') !== -1) return '👤 ';
        if (text.indexOf('sprint') !== -1) return '🏃 ';
        if (text.indexOf('acceptance') !== -1 || text.indexOf('kriteria') !== -1) return '✅ ';
        if (text.indexOf('langkah selanjutnya') !== -1 || text.indexOf('selanjutnya') !== -1) return '➡️ ';
        if (text.indexOf('tujuan') !== -1 || text.indexOf('goal') !== -1) return '🎯 ';
        if (text.indexOf('task') !== -1 || text.indexOf('tugas') !== -1) return '☑️ ';
        return '';
    }

    function plainText(markdown) {
        return String(markdown).replace(/[*_`[\]()]/g, '').trim();
    }

    function splitRow(line) {
        var row = line.trim();
        if (row.charAt(0) === '|') row = row.slice(1);
        if (row.charAt(row.length - 1) === '|') row = row.slice(0, -1);
        return row.split('|').map(function (cell) { return cell.trim(); });
    }

    function isSeparatorRow(line) {
        if (line.indexOf('|') === -1) return false;
        var cells = splitRow(line);
        return cells.length > 0 && cells.every(function (cell) {
            return /^[:\-—─\s]+$/.test(cell) && /[-—─]/.test(cell);
        });
    }

    function cellAlign(separatorCell) {
        var cell = separatorCell.trim();
        var left = cell.charAt(0) === ':';
        var right = cell.charAt(cell.length - 1) === ':';
        if (left && right) return ' style="text-align:center"';
        if (right) return ' style="text-align:right"';
        return '';
    }

    function renderList(lines, start) {
        // Bangun pohon dari indentasi, lalu render rekursif (mendukung
        // daftar bersarang seperti User Story -> poin-poinnya).
        var root = [];
        var stack = [{ indent: -1, children: root, type: null }];
        var i = start;
        while (i < lines.length) {
            var match = lines[i].match(/^(\s*)([*+-]|\d+[.)])\s+(.*)$/);
            if (!match) break;
            var indent = match[1].replace(/\t/g, '    ').length;
            var type = /^\d/.test(match[2]) ? 'ol' : 'ul';
            var content = match[3];
            var checked = null;
            var task = content.match(/^\[( |x|X)\]\s+(.*)$/);
            if (task) {
                checked = task[1].toLowerCase() === 'x';
                content = task[2];
            }
            while (stack.length > 1 && indent <= stack[stack.length - 1].indent) {
                stack.pop();
            }
            var item = { indent: indent, type: type, content: content, checked: checked, children: [] };
            stack[stack.length - 1].children.push(item);
            stack.push(item);
            i += 1;
        }
        function renderItems(items) {
            if (!items.length) return '';
            var listType = items[0].type;
            var html = '<' + listType + '>';
            items.forEach(function (item) {
                html += '<li>';
                if (item.checked !== null) {
                    html += '<input type="checkbox" disabled' + (item.checked ? ' checked' : '') + '> ';
                }
                html += inline(escapeHtml(item.content));
                if (item.children.length) html += renderItems(item.children);
                html += '</li>';
            });
            html += '</' + listType + '>';
            return html;
        }
        return { html: renderItems(root), next: i };
    }

    function render(src) {
        var lines = String(src == null ? '' : src).replace(/\r\n/g, '\n').split('\n');
        var html = '';
        var i = 0;
        while (i < lines.length) {
            var line = lines[i];
            var trimmed = line.trim();
            if (trimmed === '') { i += 1; continue; }

            // Blok kode ``` ... ```
            if (trimmed.indexOf('```') === 0) {
                var code = [];
                i += 1;
                while (i < lines.length && lines[i].trim().indexOf('```') !== 0) {
                    code.push(lines[i]);
                    i += 1;
                }
                i += 1; // baris penutup ```
                html += '<pre><code>' + escapeHtml(code.join('\n')) + '</code></pre>';
                continue;
            }

            // Heading # .. ####
            var heading = trimmed.match(/^(#{1,4})\s+(.*)$/);
            if (heading) {
                var level = heading[1].length;
                var title = heading[2];
                html += '<h' + level + '>' + headingEmoji(plainText(title)) + inline(escapeHtml(title)) + '</h' + level + '>';
                i += 1;
                continue;
            }

            // Garis pemisah --- / *** / — (AI sering memakai em dash sendiri).
            if (/^(-{3,}|\*{3,}|_{3,}|—|–)$/.test(trimmed)) {
                html += '<hr>';
                i += 1;
                continue;
            }

            // Tabel: baris header + baris pemisah, lalu baris-baris data.
            if (trimmed.charAt(0) === '|' && i + 1 < lines.length && isSeparatorRow(lines[i + 1])) {
                var headers = splitRow(line);
                var aligns = splitRow(lines[i + 1]).map(cellAlign);
                var table = '<div class="md-table-wrap"><table><thead><tr>';
                headers.forEach(function (cell, index) {
                    table += '<th' + (aligns[index] || '') + '>' + inline(escapeHtml(cell)) + '</th>';
                });
                table += '</tr></thead><tbody>';
                i += 2;
                while (i < lines.length && lines[i].trim().charAt(0) === '|') {
                    var cells = splitRow(lines[i]);
                    table += '<tr>';
                    headers.forEach(function (_, index) {
                        table += '<td' + (aligns[index] || '') + '>' + inline(escapeHtml(cells[index] || '')) + '</td>';
                    });
                    table += '</tr>';
                    i += 1;
                }
                table += '</tbody></table></div>';
                html += table;
                continue;
            }

            // Daftar (bullet, bernomor, checklist).
            if (/^(\s*)([*+-]|\d+[.)])\s+/.test(line)) {
                var list = renderList(lines, i);
                html += list.html;
                i = list.next;
                continue;
            }

            // Paragraf: gabungkan baris lanjutan sampai baris kosong/blok lain.
            var paragraph = [trimmed];
            i += 1;
            while (i < lines.length) {
                var next = lines[i].trim();
                if (next === ''
                    || next.indexOf('```') === 0
                    || /^#{1,4}\s+/.test(next)
                    || /^(-{3,}|\*{3,}|_{3,}|—|–)$/.test(next)
                    || /^(\s*)([*+-]|\d+[.)])\s+/.test(lines[i])
                    || (next.charAt(0) === '|' && i + 1 < lines.length && isSeparatorRow(lines[i + 1]))) {
                    break;
                }
                paragraph.push(next);
                i += 1;
            }
            html += '<p>' + inline(escapeHtml(paragraph.join(' '))) + '</p>';
        }
        return html;
    }

    var api = { render: render, escapeHtml: escapeHtml, inline: inline };
    if (typeof globalThis !== 'undefined') {
        globalThis.ScrumChatMarkdown = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();
