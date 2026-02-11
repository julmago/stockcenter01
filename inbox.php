<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$filter = get('filter', 'unread');
if (!in_array($filter, ['unread', 'all'], true)) {
  $filter = 'unread';
}

$pdo = db();
$user = current_user();
$user_id = (int)($user['id'] ?? 0);

$where = 'n.user_id = ?';
$params = [$user_id];
if ($filter === 'unread') {
  $where .= ' AND n.is_read = 0';
}

$st = $pdo->prepare(
  "SELECT n.id, n.type, n.is_read, n.created_at, n.read_at,
          m.id AS message_id, m.entity_type, m.entity_id, m.body, m.status, m.message_type,
          u.first_name, u.last_name, u.email
   FROM ts_notifications n
   LEFT JOIN ts_messages m ON m.id = n.message_id
   LEFT JOIN users u ON u.id = m.created_by
   WHERE {$where}
   ORDER BY n.created_at DESC"
);
$st->execute($params);
$notifications = $st->fetchAll();

function inbox_entity_url(?string $entity_type, ?int $entity_id): string {
  if (!$entity_type || !$entity_id) {
    return '#';
  }
  return match ($entity_type) {
    'product' => url_path('product_view.php?id=' . $entity_id),
    'listado' => url_path('list_view.php?id=' . $entity_id),
    default => '#',
  };
}

$csrf = csrf_token();
$api = url_path('api/notifications.php');
$messages_api = url_path('api/messages.php');
$users_st = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name, last_name, email");
$users = $users_st ? $users_st->fetchAll() : [];
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
      <h2 class="page-title">Inbox</h2>
      <span class="muted">Notificaciones de menciones</span>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Mensaje instantáneo</h3>
      </div>
      <form class="stack" data-instant-message-form>
        <div class="filters-grid">
          <label class="form-field">
            <span class="form-label">Asignar a *</span>
            <select class="form-control" name="assigned_to_user_id" required>
              <option value="">Seleccionar usuario</option>
              <?php foreach ($users as $msg_user): ?>
                <?php $msg_user_name = trim((string)($msg_user['first_name'] ?? '') . ' ' . (string)($msg_user['last_name'] ?? '')); ?>
                <option value="<?= (int)$msg_user['id'] ?>">
                  <?= e($msg_user_name !== '' ? $msg_user_name : (string)$msg_user['email']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Tipo</span>
            <select class="form-control" name="message_type">
              <option value="observacion">Observación</option>
              <option value="accion">Acción</option>
              <option value="consulta">Consulta</option>
              <option value="problema">Problema</option>
            </select>
          </label>
        </div>
        <label class="form-field">
          <span class="form-label">Texto *</span>
          <textarea class="form-control" name="body" rows="3" maxlength="5000" required></textarea>
        </label>
        <div class="inline-actions">
          <button class="btn" type="submit">Enviar</button>
          <span class="muted" data-instant-message-result></span>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header messages-header">
        <h3 class="card-title">Notificaciones</h3>
        <div class="messages-filters">
          <a class="messages-filter-btn<?= $filter === 'unread' ? ' is-active' : '' ?>" href="<?= url_path('inbox.php?filter=unread') ?>">No leídas</a>
          <a class="messages-filter-btn<?= $filter === 'all' ? ' is-active' : '' ?>" href="<?= url_path('inbox.php?filter=all') ?>">Todas</a>
          <button class="messages-filter-btn" type="button" data-mark-all>Marcar todo como leído</button>
        </div>
      </div>

      <div class="notifications-list" data-notifications-list>
        <?php if (!$notifications): ?>
          <div class="muted">No hay notificaciones.</div>
        <?php else: ?>
          <?php foreach ($notifications as $notification): ?>
            <?php
              $is_unread = (int)$notification['is_read'] === 0;
              $author = trim((string)$notification['first_name'] . ' ' . (string)$notification['last_name']);
              if ($author === '') {
                $author = (string)($notification['email'] ?? '');
              }
              $entity_url = inbox_entity_url($notification['entity_type'] ?? null, (int)($notification['entity_id'] ?? 0));
            ?>
            <div class="notification-item<?= $is_unread ? ' is-unread' : '' ?>">
              <div class="notification-meta">
                <span><strong><?= e($author !== '' ? $author : 'Sistema') ?></strong></span>
                <span><?= e((string)$notification['created_at']) ?></span>
                <?php if (!empty($notification['entity_type'])): ?>
                  <span class="message-badge"><?= e((string)$notification['entity_type']) ?> #<?= (int)$notification['entity_id'] ?></span>
                <?php endif; ?>
              </div>
              <div><?= e((string)($notification['body'] ?? 'Notificación')) ?></div>
              <div class="notification-actions">
                <?php if ($entity_url !== '#'): ?>
                  <a class="btn btn-ghost" href="<?= e($entity_url) ?>">Ir al contexto</a>
                <?php endif; ?>
                <?php if ($is_unread): ?>
                  <button class="btn" type="button" data-mark-read data-notification-id="<?= (int)$notification['id'] ?>">Marcar leída</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
  (() => {
    const api = <?= json_encode($api, JSON_UNESCAPED_UNICODE) ?>;
    const messagesApi = <?= json_encode($messages_api, JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;

    const markRead = async (payload) => {
      await fetch(`${api}?action=mark_read`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString(),
        credentials: 'same-origin',
      });
      window.location.reload();
    };

    document.querySelectorAll('[data-mark-read]').forEach((button) => {
      button.addEventListener('click', () => {
        const params = new URLSearchParams({
          notification_id: button.dataset.notificationId,
          csrf_token: csrfToken,
        });
        markRead(params);
      });
    });


    const instantForm = document.querySelector('[data-instant-message-form]');
    if (instantForm) {
      const result = instantForm.querySelector('[data-instant-message-result]');
      instantForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const assigneeField = instantForm.querySelector('select[name="assigned_to_user_id"]');
        const typeField = instantForm.querySelector('select[name="message_type"]');
        const bodyField = instantForm.querySelector('textarea[name="body"]');
        if (!assigneeField.value) {
          alert('Debés seleccionar un destinatario.');
          assigneeField.focus();
          return;
        }
        const payload = new URLSearchParams({
          entity_type: 'user',
          entity_id: assigneeField.value,
          assigned_to_user_id: assigneeField.value,
          require_assignee: '1',
          message_type: typeField.value,
          body: bodyField.value,
          csrf_token: csrfToken,
        });
        const response = await fetch(`${messagesApi}?action=create`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
          credentials: 'same-origin',
        });
        const data = await response.json();
        if (!data.ok) {
          alert(data.error || 'No se pudo enviar el mensaje.');
          return;
        }
        bodyField.value = '';
        typeField.value = 'observacion';
        assigneeField.value = '';
        if (result) {
          result.textContent = 'Mensaje enviado correctamente.';
        }
        window.location.reload();
      });
    }

    const markAllButton = document.querySelector('[data-mark-all]');
    if (markAllButton) {
      markAllButton.addEventListener('click', () => {
        const params = new URLSearchParams({
          mark_all: '1',
          csrf_token: csrfToken,
        });
        markRead(params);
      });
    }
  })();
</script>
</body>
</html>
