<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../db.php';

function ts_messages_block(string $entity_type, int $entity_id, array $options = []): void {
  $allowed = ['product', 'listado', 'pedido', 'proveedor', 'user'];
  if (!in_array($entity_type, $allowed, true) || $entity_id <= 0) {
    return;
  }
  $title = $options['title'] ?? 'Mensajes';
  $container_id = 'messages-block-' . $entity_type . '-' . $entity_id;
  $csrf = csrf_token();
  $api_base = url_path('api/messages.php');
  $notifications_api = url_path('api/notifications.php');
  ?>
  <div class="card messages-block" id="<?= e($container_id) ?>" data-messages-block
       data-entity-type="<?= e($entity_type) ?>" data-entity-id="<?= (int)$entity_id ?>"
       data-api-base="<?= e($api_base) ?>" data-notifications-api="<?= e($notifications_api) ?>"
       data-csrf="<?= e($csrf) ?>">
    <div class="card-header messages-header">
      <div>
        <h3 class="card-title"><?= e($title) ?></h3>
        <span class="muted small" data-open-count>Mensajes (0 abiertos)</span>
      </div>
      <div class="messages-filters" role="tablist">
        <button class="messages-filter-btn is-active" type="button" data-filter="all">Todos</button>
        <button class="messages-filter-btn" type="button" data-filter="open">Abiertos</button>
        <button class="messages-filter-btn" type="button" data-filter="mine">Míos</button>
        <button class="messages-filter-btn" type="button" data-filter="mentioned">Mencionado</button>
        <button class="messages-filter-btn" type="button" data-filter="archived">Archivados</button>
      </div>
    </div>
    <div class="messages-timeline" data-messages-list>
      <div class="muted">Cargando mensajes...</div>
    </div>
    <form class="message-form" data-message-form>
      <label class="form-label" for="message-body-<?= e($container_id) ?>">Nuevo mensaje</label>
      <textarea class="form-control" id="message-body-<?= e($container_id) ?>" name="body" maxlength="5000" required></textarea>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tipo</label>
          <select name="message_type">
            <option value="observacion">Observación</option>
            <option value="problema">Problema</option>
            <option value="consulta">Consulta</option>
            <option value="accion">Acción</option>
          </select>
        </div>
        <div class="form-group" style="align-self:end;">
          <button class="btn" type="submit">Enviar</button>
        </div>
      </div>
      <div class="message-hint">Podés mencionar con @usuario</div>
    </form>
  </div>
  <?php
}
?>
<script>
  (() => {
    const escapeHtml = (value) => {
      const div = document.createElement('div');
      div.textContent = value ?? '';
      return div.innerHTML;
    };

    const formatDate = (value) => {
      if (!value) return '';
      const date = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString();
    };

    const statusLabels = {
      abierto: 'Abierto',
      en_proceso: 'En proceso',
      resuelto: 'Resuelto',
      archivado: 'Archivado',
    };

    const typeLabels = {
      observacion: 'Observación',
      problema: 'Problema',
      consulta: 'Consulta',
      accion: 'Acción',
    };

    const blocks = document.querySelectorAll('[data-messages-block]');
    blocks.forEach((block) => {
      const apiBase = block.dataset.apiBase;
      const entityType = block.dataset.entityType;
      const entityId = block.dataset.entityId;
      const csrfToken = block.dataset.csrf;
      const list = block.querySelector('[data-messages-list]');
      const openCount = block.querySelector('[data-open-count]');
      const form = block.querySelector('[data-message-form]');
      const filterButtons = block.querySelectorAll('[data-filter]');
      let activeFilter = 'all';

      const setActiveFilter = (filter) => {
        activeFilter = filter;
        filterButtons.forEach((btn) => {
          btn.classList.toggle('is-active', btn.dataset.filter === filter);
        });
      };

      const renderList = (items) => {
        if (!items.length) {
          list.innerHTML = '<div class="muted">Sin mensajes todavía.</div>';
          openCount.textContent = 'Mensajes (0 abiertos)';
          return;
        }
        const openTotal = items.filter((item) => ['abierto', 'en_proceso'].includes(item.status)).length;
        openCount.textContent = `Mensajes (${openTotal} abiertos)`;
        list.innerHTML = items.map((item) => {
          const author = `${item.author_name ?? ''}`.trim();
          const badges = `
            <div class="message-badge">${escapeHtml(statusLabels[item.status] ?? item.status)}</div>
            <div class="message-badge">${escapeHtml(typeLabels[item.message_type] ?? item.message_type)}</div>
          `;
          const actions = item.can_edit ? `
            <div class="message-actions">
              <label class="form-label">Estado</label>
              <select data-message-status data-message-id="${item.id}">
                ${['abierto','en_proceso','resuelto','archivado'].map((status) => `
                  <option value="${status}" ${status === item.status ? 'selected' : ''}>
                    ${escapeHtml(statusLabels[status] ?? status)}
                  </option>
                `).join('')}
              </select>
              <button class="btn btn-ghost" type="button" data-archive-message data-message-id="${item.id}">Archivar</button>
            </div>
          ` : '';
          return `
            <div class="message-item">
              <div class="message-meta">
                <span><strong>${escapeHtml(author || 'Usuario')}</strong></span>
                <span>${escapeHtml(formatDate(item.created_at))}</span>
              </div>
              <div class="message-badges">${badges}</div>
              <div>${escapeHtml(item.body)}</div>
              ${actions}
            </div>
          `;
        }).join('');

        list.querySelectorAll('[data-message-status]').forEach((select) => {
          select.addEventListener('change', async (event) => {
            const target = event.currentTarget;
            await updateStatus(target.dataset.messageId, target.value);
          });
        });

        list.querySelectorAll('[data-archive-message]').forEach((button) => {
          button.addEventListener('click', async (event) => {
            const target = event.currentTarget;
            await archiveMessage(target.dataset.messageId);
          });
        });
      };

      const fetchList = async () => {
        const params = new URLSearchParams({
          entity_type: entityType,
          entity_id: entityId,
        });
        if (activeFilter === 'open') {
          params.set('status', 'open');
        }
        if (activeFilter === 'mine') {
          params.set('mine', '1');
        }
        if (activeFilter === 'mentioned') {
          params.set('mentioned', '1');
        }
        if (activeFilter === 'archived') {
          params.set('status', 'archivado');
        }
        const response = await fetch(`${apiBase}?${params.toString()}`, { credentials: 'same-origin' });
        const data = await response.json();
        if (!data.ok) {
          list.innerHTML = `<div class="muted">${escapeHtml(data.error || 'No se pudieron cargar los mensajes.')}</div>`;
          return;
        }
        renderList(data.items || []);
      };

      const updateStatus = async (messageId, status) => {
        const body = new URLSearchParams({
          message_id: messageId,
          status,
          csrf_token: csrfToken,
        });
        await fetch(`${apiBase}?action=status`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        fetchList();
      };

      const archiveMessage = async (messageId) => {
        const body = new URLSearchParams({
          message_id: messageId,
          csrf_token: csrfToken,
        });
        await fetch(`${apiBase}?action=archive`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        fetchList();
      };

      filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
          setActiveFilter(button.dataset.filter);
          fetchList();
        });
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const bodyField = form.querySelector('textarea[name="body"]');
        const typeField = form.querySelector('select[name="message_type"]');
        const payload = new URLSearchParams({
          entity_type: entityType,
          entity_id: entityId,
          body: bodyField.value,
          message_type: typeField.value,
          csrf_token: csrfToken,
        });
        const response = await fetch(`${apiBase}?action=create`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
          credentials: 'same-origin',
        });
        const data = await response.json();
        if (!data.ok) {
          alert(data.error || 'No se pudo guardar el mensaje.');
          return;
        }
        bodyField.value = '';
        fetchList();
      });

      setActiveFilter('all');
      fetchList();
    });
  })();
</script>
