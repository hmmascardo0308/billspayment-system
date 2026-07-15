function buildJson() {
            // Preserve line positions and empty lines so pasted lists keep their original indices.
            const ids = document.getElementById('branch-id').value.replace(/\r/g, '').split('\n').map(s => s.trim());
            const names = document.getElementById('branch-name').value.replace(/\r/g, '').split('\n').map(s => s.trim());
            const max = Math.max(ids.length, names.length);
            const out = [];
            for (let i = 0; i < max; i++) {
                const rawId = ids[i] ?? '';
                const rawName = names[i] ?? '';
                // Convert to number only when the id is strictly all digits (no leading zeros lost)
                const idToUse = rawId === '' ? '' : (/^[0-9]+$/.test(rawId) ? Number(rawId) : rawId);
                out.push({
                    branch_id: idToUse,
                    branch_name: rawName
                });
            }
            document.getElementById('pair-count').innerText = `Pairs: ${out.length}`;
            return out;
        }

        function downloadJson(data, filename = 'Branch.json') {
            const text = JSON.stringify(data, null, 2);
            const blob = new Blob([text], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);
        }

        document.getElementById('convert-btn').addEventListener('click', () => {
            const data = buildJson();
            downloadJson(data);
            document.getElementById('json-output').innerText = JSON.stringify(data, null, 2);
        });

        document.getElementById('preview-btn').addEventListener('click', () => {
            const data = buildJson();
            document.getElementById('json-output').innerText = JSON.stringify(data, null, 2);
        });

        document.getElementById('copy-btn').addEventListener('click', async () => {
            const data = buildJson();
            const text = JSON.stringify(data, null, 2);
            try { await navigator.clipboard.writeText(text); alert('JSON copied to clipboard'); }
            catch (e) { alert('Copy failed — preview and copy manually'); }
        });

        document.getElementById('clear-btn').addEventListener('click', () => {
            document.getElementById('branch-id').value = ''; document.getElementById('branch-name').value = ''; document.getElementById('json-output').innerText = ''; document.getElementById('pair-count').innerText = 'Pairs: 0';
        });