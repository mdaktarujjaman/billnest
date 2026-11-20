(function () {
    var form = document.getElementById('billnest-purchase-form');

    if (!form) {
        return;
    }

    var data = window.billnestPurchase || {};
    var products = data.products || [];
    var methods = data.methods || {};
    var restore = data.restore || null;
    var currencySymbol = data.currencySymbol || '';
    var currencyBefore = data.currencyBefore !== false;
    var itemsBody = document.getElementById('billnest-pur-items');
    var payBody = document.getElementById('billnest-pur-payments');
    var supplierField = document.getElementById('supplier_id');
    var warning = document.getElementById('billnest-pur-warning');
    var productsById = {};
    var itemIndex = 0;
    var payIndex = 0;
    var maxResults = 10;

    products.forEach(function (p) {
        productsById[p.id] = p;
    });

    function round2(number) {
        return Math.round(number * 100) / 100;
    }

    function money(number) {
        var text = number.toFixed(2);

        return currencyBefore ? currencySymbol + text : text + currencySymbol;
    }

    function fmtQty(number) {
        return String(parseFloat(number.toFixed(4)));
    }

    function searchProducts(query) {
        var tokens = query.toLowerCase().split(/\s+/).filter(Boolean);
        var joined = tokens.join(' ');
        var found = [];

        products.forEach(function (p) {
            var code = p.code.toLowerCase();
            var name = p.name.toLowerCase();
            var haystack = code + ' ' + name + ' ' + p.model.toLowerCase();

            for (var i = 0; i < tokens.length; i++) {
                if (haystack.indexOf(tokens[i]) === -1) {
                    return;
                }
            }

            var score = 3;

            if (tokens.length) {
                if (code === joined) {
                    score = 0;
                } else if (code.indexOf(tokens[0]) === 0) {
                    score = 1;
                } else if (name.indexOf(tokens[0]) === 0) {
                    score = 2;
                }
            }

            found.push({ product: p, score: score });
        });

        found.sort(function (a, b) {
            return a.score - b.score || a.product.name.localeCompare(b.product.name);
        });

        return found.slice(0, maxResults).map(function (item) {
            return item.product;
        });
    }

    function recalc() {
        var subtotal = 0;
        var paid = 0;

        itemsBody.querySelectorAll('tr').forEach(function (row) {
            var qty = parseFloat(row.querySelector('.billnest-pur-qty').value) || 0;
            var cost = parseFloat(row.querySelector('.billnest-pur-cost').value) || 0;
            var line = round2(qty * cost);

            row.querySelector('.billnest-pur-line').textContent = money(line);
            subtotal += line;
        });

        payBody.querySelectorAll('tr').forEach(function (row) {
            paid += Math.max(0, parseFloat(row.querySelector('.billnest-pur-amount').value) || 0);
        });

        subtotal = round2(subtotal);
        paid = round2(paid);

        var balance = round2(subtotal - paid);
        var message = '';

        if (balance < -0.005) {
            message = 'The amount paid is more than the purchase total.';
        } else if (balance > 0.005 && supplierField.value === '0') {
            message = 'Choose a supplier to leave a balance, or pay the full amount.';
        }

        document.getElementById('billnest-pur-subtotal').textContent = money(subtotal);
        document.getElementById('billnest-pur-paid').textContent = money(paid);
        document.getElementById('billnest-pur-balance').textContent = money(Math.max(0, balance));

        warning.hidden = message === '';
        warning.querySelector('p').textContent = message;
    }

    function wireProductSearch(row) {
        var input = row.querySelector('.billnest-pur-product-input');
        var idField = row.querySelector('.billnest-pur-product-id');
        var list = row.querySelector('.billnest-pur-results');
        var qty = row.querySelector('.billnest-pur-qty');
        var cost = row.querySelector('.billnest-pur-cost');
        var shown = [];
        var active = -1;

        function closeList() {
            list.hidden = true;
            active = -1;
        }

        function highlight() {
            list.querySelectorAll('li[data-index]').forEach(function (li) {
                var isActive = Number(li.dataset.index) === active;

                li.classList.toggle('is-active', isActive);

                if (isActive) {
                    li.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function setProduct(product) {
            idField.value = product.id;
            input.value = product.name + ' (' + product.code + ')';
            input.classList.add('is-selected');
            closeList();
        }

        function choose(product) {
            setProduct(product);
            cost.value = product.cost > 0 ? product.cost.toFixed(2) : '';

            if (qty.value === '') {
                qty.value = '1';
            }

            recalc();
            qty.focus();
            qty.select();
        }

        function renderList() {
            shown = searchProducts(input.value);
            list.innerHTML = '';

            if (!shown.length) {
                var empty = document.createElement('li');

                empty.className = 'billnest-pur-no-result';
                empty.textContent = 'No products found';
                list.appendChild(empty);
            }

            shown.forEach(function (product, i) {
                var li = document.createElement('li');
                var title = document.createElement('span');
                var meta = document.createElement('span');
                var details = [product.code, product.model].filter(Boolean).join(' · ');

                li.dataset.index = i;
                title.className = 'billnest-pur-result-name';
                title.textContent = product.name;
                meta.className = 'billnest-pur-result-meta';
                meta.textContent = (details ? details + ' · ' : '') + 'Stock: ' + fmtQty(product.stock);

                li.appendChild(title);
                li.appendChild(meta);
                li.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    choose(product);
                });

                list.appendChild(li);
            });

            active = shown.length ? 0 : -1;
            list.hidden = false;
            highlight();
        }

        input.addEventListener('input', function () {
            idField.value = '';
            input.classList.remove('is-selected');
            renderList();
        });

        input.addEventListener('focus', function () {
            if (idField.value === '') {
                renderList();
            } else {
                input.select();
            }
        });

        input.addEventListener('blur', closeList);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();

                if (list.hidden) {
                    renderList();
                } else if (shown.length) {
                    active = Math.min(active + 1, shown.length - 1);
                    highlight();
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();

                if (!list.hidden && shown.length) {
                    active = Math.max(active - 1, 0);
                    highlight();
                }
            } else if (event.key === 'Enter') {
                event.preventDefault();

                if (!list.hidden && active >= 0 && shown[active]) {
                    choose(shown[active]);
                }
            } else if (event.key === 'Escape') {
                closeList();
            }
        });

        row.setProduct = setProduct;
    }

    function addRow(focusSearch) {
        var index = itemIndex++;
        var row = document.createElement('tr');

        row.innerHTML =
            '<td><div class="billnest-pur-search">' +
            '<input type="text" class="billnest-pur-product-input" name="items[' + index + '][label]" placeholder="Search code, name or model" autocomplete="off">' +
            '<input type="hidden" class="billnest-pur-product-id" name="items[' + index + '][product_id]" value="">' +
            '<ul class="billnest-pur-results" hidden></ul>' +
            '</div></td>' +
            '<td><input type="number" step="0.01" min="0" class="billnest-pur-qty" name="items[' + index + '][qty]"></td>' +
            '<td><input type="number" step="0.01" min="0" class="billnest-pur-cost" name="items[' + index + '][cost]"></td>' +
            '<td class="billnest-pur-line">' + money(0) + '</td>' +
            '<td><button type="button" class="button billnest-pur-remove">&times;</button></td>';

        itemsBody.appendChild(row);
        wireProductSearch(row);

        row.querySelector('.billnest-pur-qty').addEventListener('input', recalc);
        row.querySelector('.billnest-pur-cost').addEventListener('input', recalc);
        row.querySelector('.billnest-pur-remove').addEventListener('click', function () {
            row.remove();
            recalc();
        });

        if (focusSearch === true) {
            row.querySelector('.billnest-pur-product-input').focus();
        }

        return row;
    }

    function syncReference(row) {
        var isCash = row.querySelector('.billnest-pur-method').value === 'cash';
        var reference = row.querySelector('.billnest-pur-reference');

        reference.disabled = isCash;
        reference.required = !isCash;

        if (isCash) {
            reference.value = '';
        }
    }

    function addPayment() {
        var index = payIndex++;
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        var select = document.createElement('select');

        select.name = 'payments[' + index + '][method]';
        select.className = 'billnest-pur-method';

        Object.keys(methods).forEach(function (key) {
            var option = document.createElement('option');

            option.value = key;
            option.textContent = methods[key];
            select.appendChild(option);
        });

        cell.appendChild(select);
        row.appendChild(cell);

        row.insertAdjacentHTML(
            'beforeend',
            '<td><input type="number" step="0.01" min="0" class="billnest-pur-amount" name="payments[' + index + '][amount]"></td>' +
            '<td><input type="text" maxlength="60" class="billnest-pur-reference" name="payments[' + index + '][reference]"></td>' +
            '<td><button type="button" class="button billnest-pur-remove">&times;</button></td>'
        );

        payBody.appendChild(row);

        select.addEventListener('change', function () {
            syncReference(row);
            recalc();
        });
        row.querySelector('.billnest-pur-amount').addEventListener('input', recalc);
        row.querySelector('.billnest-pur-remove').addEventListener('click', function () {
            row.remove();
            recalc();
        });

        syncReference(row);

        return row;
    }

    function applyRestore(saved) {
        (saved.items || []).forEach(function (item) {
            var row = addRow(false);
            var product = productsById[item.product_id];

            if (product) {
                row.setProduct(product);
            } else {
                row.querySelector('.billnest-pur-product-input').value = item.label || '';
            }

            row.querySelector('.billnest-pur-qty').value = item.qty;
            row.querySelector('.billnest-pur-cost').value = item.cost;
        });

        if (!itemsBody.querySelector('tr')) {
            addRow(false);
        }

        (saved.payments || []).forEach(function (line) {
            var row = addPayment();
            var method = row.querySelector('.billnest-pur-method');

            if (methods[line.method] !== undefined) {
                method.value = line.method;
            }

            syncReference(row);
            row.querySelector('.billnest-pur-amount').value = line.amount;

            if (method.value !== 'cash') {
                row.querySelector('.billnest-pur-reference').value = line.reference;
            }
        });

        if (!payBody.querySelector('tr')) {
            addPayment();
        }
    }

    document.getElementById('billnest-pur-add-item').addEventListener('click', function () {
        addRow(true);
    });
    document.getElementById('billnest-pur-add-payment').addEventListener('click', addPayment);
    supplierField.addEventListener('change', recalc);

    if (restore) {
        applyRestore(restore);
    } else {
        addRow(false);
        addPayment();
    }

    recalc();
})();