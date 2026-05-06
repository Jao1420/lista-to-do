/* ================================================================
   Auditorias – app.js
   Drill-down: audit cards → kanban modal → item detail modal
   ================================================================ */

// ── Modal helpers ─────────────────────────────────────────────────
function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    // only restore scroll if no other modal is open
    if (!document.querySelector('.modal.open')) {
        document.body.style.overflow = '';
    }
}

// Close on Escape
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    // close innermost open modal first
    const modals = Array.from(document.querySelectorAll('.modal.open'));
    if (modals.length) closeModal(modals[modals.length - 1].id);
});

// ── Auto-open from URL after form redirect ────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const auditId = window.__AUTO_OPEN__ || 0;
    if (auditId) {
        openModal('modal-audit-' + auditId);
        // Remove audit_id from URL so modal doesn't reopen on refresh
        window.history.replaceState({}, '', window.location.pathname);
    }
});

// ── New audit form: sync planned date max with deadline ───────────
document.addEventListener('DOMContentLoaded', () => {
    const deadlineInput = document.getElementById('audit_deadline_date');
    const plannedInput  = document.getElementById('audit_default_planned');
    if (!deadlineInput || !plannedInput) return;

    function syncMax() {
        const val = deadlineInput.value;
        plannedInput.max = val;
        if (val && plannedInput.value > val) plannedInput.value = val;
    }
    deadlineInput.addEventListener('change', syncMax);
    deadlineInput.addEventListener('input',  syncMax);
});

// ── Open "Novo Item" modal ────────────────────────────────────────
function openNewItemModal(auditId, deadline) {
    document.getElementById('new-item-audit-id').value = auditId;
    const dateInput = document.getElementById('new-item-date');
    dateInput.max = deadline;
    if (dateInput.value > deadline) dateInput.value = deadline;
    openModal('modal-new-item');
}

// ── Open item detail modal ────────────────────────────────────────
function openItemModal(card) {
    // stop click propagating to backdrop / drag
    event.stopPropagation();

    const d = card.dataset;

    document.getElementById('modal-item-title').textContent = d.title;
    document.getElementById('edit-audit-id').value   = d.auditId;
    document.getElementById('edit-item-id').value    = d.itemId;
    document.getElementById('del-audit-id').value    = d.auditId;
    document.getElementById('del-item-id').value     = d.itemId;
    document.getElementById('edit-responsible').value = d.responsible;
    document.getElementById('edit-notes').value      = d.notes || '';
    document.getElementById('edit-priority').value   = d.priority;
    document.getElementById('edit-status').value     = d.status;

    const dateInput = document.getElementById('edit-planned-date');
    dateInput.max   = d.deadline;
    dateInput.value = d.plannedDate;

    openModal('modal-item');
}

// Delete button inside item modal
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btn-delete-item').addEventListener('click', () => {
        if (!confirm('Excluir este item? Esta ação não pode ser desfeita.')) return;
        document.getElementById('form-item-delete').submit();
    });
});

// ── Drag-and-drop between kanban columns ─────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    let draggedCard = null;

    function getCardPosition(card) {
        const siblings = Array.from(card.parentElement.querySelectorAll('.card'));
        return (siblings.indexOf(card) + 1) * 10;
    }

    async function persistStatus(card, newStatus) {
        const board = card.closest('.board');
        const csrf      = board.dataset.csrf;
        const auditId   = board.dataset.auditId;
        const itemId    = card.dataset.itemId;
        const posIndex  = getCardPosition(card);

        const payload = new URLSearchParams({
            csrf, action: 'update_item_status',
            audit_id: auditId, item_id: itemId,
            status: newStatus, ordem_card: String(posIndex),
        });

        const res = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload.toString(),
        });

        if (!res.ok) throw new Error('Falha ao salvar status.');

        // update data attribute so item modal opens with correct status
        card.dataset.status = newStatus;
    }

    function refreshColumnCount(columnCards) {
        const col   = columnCards.closest('.column');
        const badge = col.querySelector('.col-count');
        if (badge) badge.textContent = String(col.querySelectorAll('.card').length);
    }

    function refreshBoardProgress(board) {
        const total = board.querySelectorAll('.card').length;
        const done  = board.querySelectorAll('.column[data-status="concluido"] .card').length;
        const pct   = total > 0 ? Math.round(done / total * 100) : 0;
        const modal = board.closest('.modal');
        if (!modal) return;
        const progSpan = Array.from(modal.querySelectorAll('.mk-meta span'))
            .find(s => s.textContent.includes('Progresso'));
        if (progSpan) {
            const strong = progSpan.querySelector('strong');
            if (strong) strong.textContent = `${done}/${total} (${pct}%)`;
        }
        const bar = modal.querySelector('.mk-progress .progress-bar');
        if (bar) bar.style.width = pct + '%';
    }

    // attach drag listeners to all cards (including newly opened modals)
    function attachDrag() {
        document.querySelectorAll('.card:not([data-drag-bound])').forEach((card) => {
            card.dataset.dragBound = '1';

            card.addEventListener('dragstart', (e) => {
                draggedCard = card;
                card.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                draggedCard = null;
                document.querySelectorAll('.cards').forEach(c => c.classList.remove('drop-target'));
            });
        });
    }

    document.querySelectorAll('.cards').forEach((zone) => {
        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.classList.add('drop-target');
        });

        zone.addEventListener('dragleave', () => {
            zone.classList.remove('drop-target');
        });

        zone.addEventListener('drop', async (e) => {
            e.preventDefault();
            zone.classList.remove('drop-target');
            if (!draggedCard) return;

            const prevZone = draggedCard.parentElement;
            zone.appendChild(draggedCard);

            refreshColumnCount(zone);
            if (prevZone !== zone) refreshColumnCount(prevZone);
            const board = zone.closest('.board');
            if (board) refreshBoardProgress(board);

            const newStatus = zone.dataset.status;
            try {
                await persistStatus(draggedCard, newStatus);
            } catch {
                alert('Não foi possível salvar o status. Recarregue a página.');
                window.location.reload();
            }
        });
    });

    attachDrag();
});
(() => {
    const deadlineInput = document.getElementById('audit_deadline_date');
    const plannedInput = document.getElementById('audit_default_planned');

    if (deadlineInput && plannedInput) {
        function syncMax() {
            const val = deadlineInput.value;
            plannedInput.max = val;
            if (val && plannedInput.value > val) {
                plannedInput.value = val;
            }
        }
        deadlineInput.addEventListener('change', syncMax);
        deadlineInput.addEventListener('input', syncMax);
        syncMax();
    }
})();

(() => {
    const board = document.querySelector('.board');
    if (!board) {
        return;
    }

    const csrf = board.dataset.csrf;
    const auditId = board.dataset.auditId;
    let draggedCard = null;

    const statusOrder = {
        nao_iniciado: 1,
        em_andamento: 2,
        em_revisao: 3,
        bloqueado: 4,
        concluido: 5,
    };

    function getCardPosition(card) {
        const cards = Array.from(card.parentElement.querySelectorAll('.card'));
        return (cards.indexOf(card) + 1) * 10;
    }

    async function persistStatus(card, newStatus) {
        const itemId = card.dataset.itemId;
        const positionIndex = getCardPosition(card);

        const payload = new URLSearchParams();
        payload.set('csrf', csrf);
        payload.set('action', 'update_item_status');
        payload.set('audit_id', auditId);
        payload.set('item_id', itemId);
        payload.set('status', newStatus);
        payload.set('ordem_card', String(positionIndex));

        const response = await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload.toString(),
        });

        if (!response.ok) {
            throw new Error('Falha ao atualizar status.');
        }

        const statusSelect = card.querySelector('.status-form select');
        if (statusSelect) {
            statusSelect.value = newStatus;
        }
    }

    function refreshColumnCounts() {
        document.querySelectorAll('.column').forEach((column) => {
            const count = column.querySelectorAll('.card').length;
            const badge = column.querySelector('.col-count');
            if (badge) {
                badge.textContent = String(count);
            }
        });
    }

    function reorderWithinColumn(column) {
        const cards = Array.from(column.querySelectorAll('.card'));
        cards.sort((a, b) => {
            const statusA = a.closest('.cards').dataset.status;
            const statusB = b.closest('.cards').dataset.status;
            return statusOrder[statusA] - statusOrder[statusB];
        });

        cards.forEach((card) => {
            column.appendChild(card);
        });
    }

    board.querySelectorAll('.card').forEach((card) => {
        card.addEventListener('dragstart', () => {
            draggedCard = card;
            card.classList.add('dragging');
        });

        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            draggedCard = null;
            board.querySelectorAll('.cards').forEach((c) => c.classList.remove('drop-target'));
        });
    });

    board.querySelectorAll('.cards').forEach((columnCards) => {
        columnCards.addEventListener('dragover', (event) => {
            event.preventDefault();
            columnCards.classList.add('drop-target');
        });

        columnCards.addEventListener('dragleave', () => {
            columnCards.classList.remove('drop-target');
        });

        columnCards.addEventListener('drop', async (event) => {
            event.preventDefault();
            columnCards.classList.remove('drop-target');

            if (!draggedCard) {
                return;
            }

            columnCards.appendChild(draggedCard);
            reorderWithinColumn(columnCards);
            refreshColumnCounts();
            refreshBoardProgress(board);

            const newStatus = columnCards.dataset.status;
            try {
                await persistStatus(draggedCard, newStatus);
            } catch (error) {
                window.alert('Nao foi possivel atualizar o status. Recarregue a pagina e tente novamente.');
                window.location.reload();
            }
        });
    });
})();
