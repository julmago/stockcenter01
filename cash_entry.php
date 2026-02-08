<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasPerm('cashbox_create_entry'), 'Sin permiso para crear entradas.');

$cashbox = require_cashbox_selected();
$user = current_user();

$message = '';
$error = '';

$denom_st = db()->prepare("SELECT id, value FROM cash_denominations WHERE cashbox_id = ? AND is_active = 1 ORDER BY sort_order ASC, value ASC");
$denom_st->execute([(int)$cashbox['id']]);
$denominations = $denom_st->fetchAll();

if (is_post() && post('action') === 'create_entry') {
  $detail = trim((string)post('detail'));
  $amount_raw = trim((string)post('amount'));
  $amount_raw = str_replace([' ', ','], ['', '.'], $amount_raw);
  $amount = (float)$amount_raw;

  if ($detail === '') {
    $error = 'El detalle es obligatorio.';
  } elseif ($amount <= 0) {
    $error = 'El efectivo debe ser mayor a 0.';
  } else {
    $st = db()->prepare("INSERT INTO cash_movements (cashbox_id, type, detail, amount, user_id) VALUES (?, 'entry', ?, ?, ?)");
    $st->execute([(int)$cashbox['id'], $detail, $amount, (int)$user['id']]);
    $message = 'Entrada registrada correctamente.';
  }
}

$recent_st = db()->prepare("SELECT cm.detail, cm.amount, cm.created_at, u.first_name, u.last_name
  FROM cash_movements cm
  JOIN users u ON u.id = cm.user_id
  WHERE cm.cashbox_id = ? AND cm.type = 'entry'
  ORDER BY cm.created_at DESC
  LIMIT 10");
$recent_st->execute([(int)$cashbox['id']]);
$recent_movements = $recent_st->fetchAll();

$responsible_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($responsible_name === '') {
  $responsible_name = $user['email'] ?? 'Usuario';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Entrada de caja</h2>
      <span class="muted">Caja activa: <?= e($cashbox['name']) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="cash-layout">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Nueva entrada</h3>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="create_entry">
          <div class="form-group">
            <label class="form-label">Detalle</label>
            <input class="form-control" type="text" name="detail" required maxlength="255">
          </div>
          <div class="form-group">
            <label class="form-label">Efectivo</label>
            <input class="form-control" type="number" name="amount" step="0.01" min="0" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Responsable</label>
              <input class="form-control" type="text" value="<?= e($responsible_name) ?>" readonly>
            </div>
            <div class="form-group">
              <label class="form-label">Fecha y hora</label>
              <input class="form-control" type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Registrar entrada</button>
            <a class="btn btn-ghost" href="<?= url_path('cash_select.php') ?>">Volver</a>
          </div>
        </form>
      </div>

      <div class="cash-widget">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Contador de billetes</h3>
          </div>
          <?php if ($denominations): ?>
            <?php foreach ($denominations as $denom): ?>
              <div class="denom-row">
                <span>$<?= number_format((int)$denom['value'], 0, ',', '.') ?></span>
                <input class="form-control" type="number" min="0" value="0" data-denom-value="<?= (int)$denom['value'] ?>">
              </div>
            <?php endforeach; ?>
            <div class="denom-row">
              <strong>Total</strong>
              <strong data-denom-total>0</strong>
            </div>
            <div class="form-actions">
              <button class="btn btn-ghost" type="button" data-denom-copy>Usar total como efectivo</button>
            </div>
          <?php else: ?>
            <div class="alert alert-info">No hay billetes configurados para esta caja.</div>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Calculadora</h3>
          </div>
          <input class="calculator-display" type="text" data-calculator-display value="0" readonly>
          <div class="calculator-grid" data-calculator>
            <button type="button" data-value="7">7</button>
            <button type="button" data-value="8">8</button>
            <button type="button" data-value="9">9</button>
            <button type="button" data-value="/">/</button>
            <button type="button" data-value="4">4</button>
            <button type="button" data-value="5">5</button>
            <button type="button" data-value="6">6</button>
            <button type="button" data-value="*">*</button>
            <button type="button" data-value="1">1</button>
            <button type="button" data-value="2">2</button>
            <button type="button" data-value="3">3</button>
            <button type="button" data-value="-">-</button>
            <button type="button" data-value="0">0</button>
            <button type="button" data-value=".">.</button>
            <button type="button" data-action="back">⌫</button>
            <button type="button" data-value="+">+</button>
            <button type="button" data-action="clear">C</button>
            <button type="button" data-action="vat-plus">+ IVA</button>
            <button type="button" data-action="vat-minus">- IVA</button>
            <button type="button" data-action="equals">=</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Últimas entradas</h3>
      </div>
      <?php if ($recent_movements): ?>
        <table class="cash-table">
          <thead>
            <tr>
              <th>Detalle</th>
              <th>Importe</th>
              <th>Responsable</th>
              <th>Fecha/Hora</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_movements as $movement): ?>
              <tr>
                <td><?= e($movement['detail']) ?></td>
                <td>$<?= number_format((float)$movement['amount'], 2, ',', '.') ?></td>
                <td><?= e(trim(($movement['first_name'] ?? '') . ' ' . ($movement['last_name'] ?? ''))) ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert alert-info">Todavía no hay entradas registradas.</div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
  (() => {
    const denomInputs = document.querySelectorAll('[data-denom-value]');
    const totalEl = document.querySelector('[data-denom-total]');
    const copyButton = document.querySelector('[data-denom-copy]');
    const amountInput = document.querySelector('input[name="amount"]');

    const updateTotal = () => {
      let total = 0;
      denomInputs.forEach((input) => {
        const value = parseInt(input.dataset.denomValue || '0', 10);
        const qty = parseInt(input.value || '0', 10);
        if (!Number.isNaN(value) && !Number.isNaN(qty)) {
          total += value * qty;
        }
      });
      if (totalEl) {
        totalEl.textContent = total.toLocaleString('es-AR');
      }
      return total;
    };

    denomInputs.forEach((input) => {
      input.addEventListener('input', updateTotal);
    });

    copyButton?.addEventListener('click', () => {
      const total = updateTotal();
      if (amountInput) {
        amountInput.value = (total / 1).toFixed(2);
      }
    });

    const display = document.querySelector('[data-calculator-display]');
    const keypad = document.querySelector('[data-calculator]');
    let expression = '0';

    const setDisplay = (value) => {
      expression = value;
      if (display) {
        display.value = value;
      }
    };

    const evaluateExpression = () => {
      const safe = expression.replace(',', '.');
      if (!/^[0-9+\-*/().\s]+$/.test(safe)) {
        return null;
      }
      try {
        // eslint-disable-next-line no-new-func
        const result = Function(`return (${safe})`)();
        if (typeof result === 'number' && Number.isFinite(result)) {
          return result;
        }
      } catch (_e) {
        return null;
      }
      return null;
    };

    keypad?.addEventListener('click', (event) => {
      const target = event.target.closest('button');
      if (!target) return;
      const value = target.getAttribute('data-value');
      const action = target.getAttribute('data-action');

      if (action === 'clear') {
        setDisplay('0');
        return;
      }
      if (action === 'back') {
        const next = expression.length > 1 ? expression.slice(0, -1) : '0';
        setDisplay(next);
        return;
      }
      if (action === 'equals') {
        const result = evaluateExpression();
        if (result === null) {
          setDisplay('0');
        } else {
          setDisplay(result.toFixed(2));
        }
        return;
      }
      if (action === 'vat-plus') {
        const current = evaluateExpression();
        if (current !== null) {
          setDisplay((current * 1.21).toFixed(2));
        }
        return;
      }
      if (action === 'vat-minus') {
        const current = evaluateExpression();
        if (current !== null) {
          setDisplay((current / 1.21).toFixed(2));
        }
        return;
      }

      if (value) {
        const next = expression === '0' ? value : `${expression}${value}`;
        setDisplay(next);
      }
    });
  })();
</script>

</body>
</html>
