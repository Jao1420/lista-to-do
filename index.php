<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/src/bootstrap.php';

$pdo = db();
ensureSchema($pdo);

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$csrf = $_SESSION['csrf'];
$errors = [];

function requireCsrf(string $token): void
{
    if (!isset($_POST['csrf']) || !hash_equals($token, (string) $_POST['csrf'])) {
        throw new RuntimeException('Token CSRF invalido. Recarregue a pagina e tente novamente.');
    }
}

function validDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function auditById(PDO $pdo, int $auditId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM audits WHERE id = :id');
    $stmt->execute(['id' => $auditId]);
    $audit = $stmt->fetch();
    return $audit ?: null;
}

function saveItemPdf(int $auditId, int $itemId): ?string
{
    if (!isset($_FILES['arquivo_pdf']) || $_FILES['arquivo_pdf']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES['arquivo_pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao fazer upload do arquivo: ' . $_FILES['arquivo_pdf']['error']);
    }

    $file = $_FILES['arquivo_pdf'];
    $tmpName = (string) $file['tmp_name'];
    $originalName = (string) $file['name'];

    // Validar tipo de arquivo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    if ($mimeType !== 'application/pdf') {
        throw new RuntimeException('Apenas arquivos PDF são permitidos.');
    }

    // Criar diretório se não existir
    $publicDir = __DIR__ . '/public';
    if (!is_dir($publicDir)) {
        mkdir($publicDir, 0755, true);
    }

    // Gerar nome único para o arquivo
    $filename = sprintf('audit_%s_item_%s_%s.pdf', $auditId, $itemId, time());
    $filepath = $publicDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $filepath)) {
        throw new RuntimeException('Falha ao salvar o arquivo PDF.');
    }

    return 'public/' . $filename;
}

function deleteItemPdf(?string $filepath): void
{
    if (!$filepath) {
        return;
    }

    $fullPath = __DIR__ . '/' . $filepath;
    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete_audit') {
            requireCsrf($csrf);
            $auditId = (int) ($_POST['audit_id'] ?? 0);
            
            // Obter dados da auditoria antes de deletar
            $getAudit = $pdo->prepare('SELECT arquivo_pdf FROM audits WHERE id = :id');
            $getAudit->execute(['id' => $auditId]);
            $audit = $getAudit->fetch();
            
            // Deletar arquivo PDF do disco se existir
            if ($audit && $audit['arquivo_pdf']) {
                $filePath = __DIR__ . '/' . $audit['arquivo_pdf'];
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }
            
            $del = $pdo->prepare('DELETE FROM audits WHERE id = :id');
            $del->execute(['id' => $auditId]);
            redirect('?');
        }

        if ($action === 'finalize_audit') {
            requireCsrf($csrf);
            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $fin = $pdo->prepare('UPDATE audits SET finalizado = 1 WHERE id = :id');
            $fin->execute(['id' => $auditId]);
            redirect('?');
        }

        if ($action === 'create_audit') {
            requireCsrf($csrf);

            $linha = trim((string) ($_POST['linha'] ?? ''));
            $auditDate = trim((string) ($_POST['audit_date'] ?? ''));
            $clientName = trim((string) ($_POST['client_name'] ?? ''));
            $auditOwner = trim((string) ($_POST['audit_owner'] ?? ''));
            $deadlineDate = trim((string) ($_POST['deadline_date'] ?? ''));

            if ($linha === '' || $clientName === '' || $auditOwner === '' || !validDate($deadlineDate)) {
                throw new RuntimeException('Preencha todos os campos obrigatorios com data limite valida.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO audits (linha, data_auditoria, cliente, responsavel, data_limite) VALUES (:linha, :data_auditoria, :cliente, :responsavel, :data_limite)');
            $stmt->execute([
                'linha'          => $linha,
                'data_auditoria' => $auditDate,
                'cliente'        => $clientName,
                'responsavel'    => $auditOwner,
                'data_limite'    => $deadlineDate,
            ]);

            $newId = (int) $pdo->lastInsertId();

            $templates = $pdo->query('SELECT id, titulo, categoria, ordem FROM checklist_templates WHERE ativo = 1 ORDER BY ordem, id')->fetchAll();
            $insertItemStmt = $pdo->prepare('INSERT INTO audit_items (auditoria_id, template_id, titulo, categoria, responsavel, data_prevista, data_limite_auditoria, status, prioridade, ordem_card) VALUES (:auditoria_id, :template_id, :titulo, :categoria, :responsavel, :data_prevista, :data_limite_auditoria, :status, :prioridade, :ordem_card)');
            $position = 0;
            foreach ($templates as $tpl) {
                $position += 10;
                $insertItemStmt->execute([
                    'auditoria_id'          => $newId,
                    'template_id'         => (int) $tpl['id'],
                    'titulo'                => $tpl['titulo'],
                    'categoria'             => $tpl['categoria'],
                    'responsavel'           => '',
                    'data_prevista'         => $deadlineDate,
                    'data_limite_auditoria' => $deadlineDate,
                    'status'              => 'nao_iniciado',
                    'prioridade'            => 'media',
                    'ordem_card'          => $position,
                ]);
            }

            $pdo->commit();
            redirect('?audit_id=' . $newId);
        }

        if ($action === 'load_template_items') {
            requireCsrf($csrf);

            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $audit = auditById($pdo, $auditId);

            if (!$audit) {
                throw new RuntimeException('Auditoria nao encontrada.');
            }

            $pdo->beginTransaction();

            $templates = $pdo->query('SELECT id, titulo, categoria, ordem FROM checklist_templates WHERE ativo = 1 ORDER BY ordem, id')->fetchAll();
            $existingTemplateStmt = $pdo->prepare('SELECT template_id FROM audit_items WHERE auditoria_id = :auditoria_id AND template_id IS NOT NULL');
            $existingTemplateStmt->execute(['auditoria_id' => $auditId]);
            $existingTemplateIds = array_map('intval', array_column($existingTemplateStmt->fetchAll(), 'template_id'));
            $existingMap = array_flip($existingTemplateIds);

            $insertStmt = $pdo->prepare('INSERT INTO audit_items (auditoria_id, template_id, titulo, categoria, responsavel, data_prevista, data_limite_auditoria, status, prioridade, ordem_card) VALUES (:auditoria_id, :template_id, :titulo, :categoria, :responsavel, :data_prevista, :data_limite_auditoria, :status, :prioridade, :ordem_card)');

            $position = 0;
            foreach ($templates as $tpl) {
                $templateId = (int) $tpl['id'];
                if (isset($existingMap[$templateId])) {
                    continue;
                }

                $position += 10;
                $insertStmt->execute([
                    'auditoria_id'          => $auditId,
                    'template_id' => $templateId,
                    'titulo'                => $tpl['titulo'],
                    'categoria'             => $tpl['categoria'],
                    'responsavel'           => '',
                    'data_prevista'         => $audit['data_limite'],
                    'data_limite_auditoria' => $audit['data_limite'],
                    'status' => 'nao_iniciado',
                    'prioridade'            => 'media',
                    'ordem_card' => $position,
                ]);
            }

            $pdo->commit();
            redirect('?audit_id=' . $auditId);
        }

        if ($action === 'create_item') {
            requireCsrf($csrf);

            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $responsible = trim((string) ($_POST['responsible'] ?? ''));
            $plannedEndDate = trim((string) ($_POST['planned_end_date'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'media'));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $audit = auditById($pdo, $auditId);
            if (!$audit) {
                throw new RuntimeException('Auditoria nao encontrada.');
            }

            if ($title === '' || $responsible === '' || !validDate($plannedEndDate)) {
                throw new RuntimeException('Preencha titulo, responsavel e data prevista.');
            }

            if ($plannedEndDate > $audit['data_limite']) {
                throw new RuntimeException('A data prevista do item deve ser menor ou igual a data limite da auditoria.');
            }

            if (!in_array($priority, ['baixa', 'media', 'alta'], true)) {
                $priority = 'media';
            }

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(ordem_card), 0) + 10 AS next_position FROM audit_items WHERE auditoria_id = :auditoria_id');
            $stmt->execute(['auditoria_id' => $auditId]);
            $nextPosition = (int) ($stmt->fetch()['next_position'] ?? 10);

            $insert = $pdo->prepare('INSERT INTO audit_items (auditoria_id, titulo, categoria, responsavel, data_prevista, data_limite_auditoria, status, prioridade, ordem_card, observacao) VALUES (:auditoria_id, :titulo, :categoria, :responsavel, :data_prevista, :data_limite_auditoria, :status, :prioridade, :ordem_card, :observacao)');
            $insert->execute([
                'auditoria_id'          => $auditId,
                'titulo'                => $title,
                'categoria'             => ($category === '' ? null : $category),
                'responsavel'           => $responsible,
                'data_prevista'         => $plannedEndDate,
                'data_limite_auditoria' => $audit['data_limite'],
                'status'                => 'nao_iniciado',
                'prioridade'            => $priority,
                'ordem_card'            => $nextPosition,
                'observacao'            => ($notes === '' ? null : $notes),
            ]);

            redirect('?audit_id=' . $auditId);
        }

        if ($action === 'update_item_meta') {
            requireCsrf($csrf);

            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $responsible = trim((string) ($_POST['responsible'] ?? ''));
            $plannedEndDate = trim((string) ($_POST['planned_end_date'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'media'));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $audit = auditById($pdo, $auditId);
            if (!$audit) {
                throw new RuntimeException('Auditoria nao encontrada.');
            }

            if ($responsible === '' || !validDate($plannedEndDate)) {
                throw new RuntimeException('Responsavel e data prevista sao obrigatorios.');
            }

            if ($plannedEndDate > $audit['data_limite']) {
                throw new RuntimeException('A data prevista do item deve ser menor ou igual a data limite da auditoria.');
            }

            if (!in_array($priority, ['baixa', 'media', 'alta'], true)) {
                $priority = 'media';
            }

            $status = trim((string) ($_POST['status'] ?? ''));
            $allowedStatuses = ['nao_iniciado', 'em_andamento', 'em_revisao', 'concluido', 'bloqueado'];
            $updateStatus = in_array($status, $allowedStatuses, true);

            // Processar upload de PDF
            $novaPdf = null;
            if (isset($_FILES['arquivo_pdf']) && $_FILES['arquivo_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
                $novaPdf = saveItemPdf($auditId, $itemId);
            }

            // Se houver novo PDF, obter PDF anterior para deletar
            if ($novaPdf !== null) {
                $getItem = $pdo->prepare('SELECT arquivo_pdf FROM audit_items WHERE id = :id AND auditoria_id = :auditoria_id');
                $getItem->execute(['id' => $itemId, 'auditoria_id' => $auditId]);
                $oldItem = $getItem->fetch();
                if ($oldItem && $oldItem['arquivo_pdf']) {
                    deleteItemPdf($oldItem['arquivo_pdf']);
                }
            }

            $sql = 'UPDATE audit_items SET responsavel = :responsavel, data_prevista = :data_prevista, data_limite_auditoria = :data_limite_auditoria, prioridade = :prioridade, observacao = :observacao';
            if ($updateStatus) {
                $sql .= ', status = :status, concluido_em = :concluido_em';
            }
            if ($novaPdf !== null) {
                $sql .= ', arquivo_pdf = :arquivo_pdf';
            }
            $sql .= ' WHERE id = :id AND auditoria_id = :auditoria_id';

            $params = [
                'responsavel'           => $responsible,
                'data_prevista'         => $plannedEndDate,
                'data_limite_auditoria' => $audit['data_limite'],
                'prioridade'            => $priority,
                'observacao'            => ($notes === '' ? null : $notes),
                'id'                    => $itemId,
                'auditoria_id'          => $auditId,
            ];
            if ($updateStatus) {
                $params['status']       = $status;
                $params['concluido_em'] = ($status === 'concluido' ? date('Y-m-d H:i:s') : null);
            }
            if ($novaPdf !== null) {
                $params['arquivo_pdf'] = $novaPdf;
            }

            $update = $pdo->prepare($sql);
            $update->execute($params);

            redirect('?audit_id=' . $auditId);
        }

        if ($action === 'update_item_status') {
            requireCsrf($csrf);

            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'nao_iniciado'));
            $positionIndex = (int) ($_POST['ordem_card'] ?? 0);

            $allowed = ['nao_iniciado', 'em_andamento', 'em_revisao', 'concluido', 'bloqueado'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Status invalido.');
            }

            $update = $pdo->prepare('UPDATE audit_items SET status = :status, ordem_card = :ordem_card, concluido_em = :concluido_em WHERE id = :id AND auditoria_id = :auditoria_id');
            $update->execute([
                'status'       => $status,
                'ordem_card'   => $positionIndex,
                'concluido_em' => ($status === 'concluido' ? date('Y-m-d H:i:s') : null),
                'id'           => $itemId,
                'auditoria_id' => $auditId,
            ]);

            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }

            redirect('?audit_id=' . $auditId);
        }

        if ($action === 'delete_item') {
            requireCsrf($csrf);

            $auditId = (int) ($_POST['audit_id'] ?? 0);
            $itemId = (int) ($_POST['item_id'] ?? 0);

            // Obter dados do item antes de deletar
            $getItem = $pdo->prepare('SELECT arquivo_pdf FROM audit_items WHERE id = :id AND auditoria_id = :auditoria_id');
            $getItem->execute(['id' => $itemId, 'auditoria_id' => $auditId]);
            $item = $getItem->fetch();

            // Deletar arquivo PDF do disco se existir
            if ($item && $item['arquivo_pdf']) {
                deleteItemPdf($item['arquivo_pdf']);
            }

            $stmt = $pdo->prepare('DELETE FROM audit_items WHERE id = :id AND auditoria_id = :auditoria_id');
            $stmt->execute(['id' => $itemId, 'auditoria_id' => $auditId]);

            redirect('?audit_id=' . $auditId);
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$audits = $pdo->query('SELECT * FROM audits WHERE finalizado = 0 ORDER BY criado_em DESC')->fetchAll();

$allItemsByAudit = [];
if ($audits) {
    $auditIds = implode(',', array_map(fn(array $a): int => (int) $a['id'], $audits));
    $itemStmt = $pdo->query("SELECT * FROM audit_items WHERE auditoria_id IN ({$auditIds}) ORDER BY auditoria_id, FIELD(status,'nao_iniciado','em_andamento','em_revisao','bloqueado','concluido'), ordem_card, id");
    foreach ($itemStmt->fetchAll() as $row) {
        $allItemsByAudit[(int) $row['auditoria_id']][] = $row;
    }
}

$statusColumns = [
    'nao_iniciado' => 'Não iniciado',
    'em_andamento' => 'Em andamento',
    'em_revisao'   => 'Em revisão',
    // 'bloqueado'    => 'Bloqueado',
    'concluido'    => 'Concluído',
];

$today = new DateTimeImmutable('today');

function visualState(array $item, DateTimeImmutable $today): string
{
    if ($item['status'] === 'concluido') {
        return 'v-done';
    }
    $planned = DateTimeImmutable::createFromFormat('Y-m-d', (string) $item['data_prevista']);
    if (!$planned) {
        return 'v-neutral';
    }
    if ($planned < $today) {
        return 'v-overdue';
    }
    $days = (int) $today->diff($planned)->format('%a');
    if ($days <= 2) {
        return 'v-warning';
    }
    return 'v-ok';
}

$auditData = [];
foreach ($audits as $audit) {
    $id = (int) $audit['id'];
    $items = $allItemsByAudit[$id] ?? [];
    $total = count($items);
    $done  = count(array_filter($items, static fn(array $i): bool => $i['status'] === 'concluido'));

    $grouped = [];
    foreach ($statusColumns as $key => $_) {
        $grouped[$key] = [];
    }
    foreach ($items as $item) {
        $grouped[$item['status']][] = $item;
    }

    $auditData[$id] = [
        'items'    => $items,
        'grouped'  => $grouped,
        'total'    => $total,
        'done'     => $done,
        'progress' => $total > 0 ? (int) round($done / $total * 100) : 0,
    ];
}

$autoOpenAuditId = isset($_GET['audit_id']) ? (int) $_GET['audit_id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditorias – Checklist</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="app-shell">

    <header class="topbar">
        <div>
            <h1>Checklist de Auditoria</h1>
            <p>Acompanhamento por atividade com responsável e prazo.</p>
        </div>
        <div class="topbar-right">
            <span class="badge"><?= h(date('d/m/Y H:i')) ?></span>
            <button class="btn-new-audit" onclick="openModal('modal-new-audit')">+ Nova Auditoria</button>
        </div>
    </header>

    <?php if ($errors): ?>
        <section class="alerts">
            <?php foreach ($errors as $err): ?>
                <div class="alert"><?= h($err) ?></div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="audits-grid">
        <?php if (!$audits): ?>
            <div class="empty-state">
                <p>Nenhuma auditoria cadastrada ainda.</p>
                <button onclick="openModal('modal-new-audit')">+ Criar primeira auditoria</button>
            </div>
        <?php else: ?>
            <?php foreach ($audits as $audit):
                $id       = (int) $audit['id'];
                $data     = $auditData[$id];
                $progress = $data['progress'];
            ?>
            <div class="audit-card-wrapper">
                <div class="audit-card" onclick="openModal('modal-audit-<?= $id ?>')">
                    <div class="ac-header">
                        <span class="ac-linha">Linha <?= h($audit['linha']) ?></span>
                        <span class="ac-client"><?= h($audit['cliente']) ?></span>
                    </div>
                    <div class="ac-body">
                        <div class="ac-row"><span>Responsável</span><strong><?= h($audit['responsavel']) ?></strong></div>
                        <div class="ac-row"><span>Data limite</span><strong><?= h(formatDate($audit['data_limite'])) ?></strong></div>
                        <div class="ac-row"><span>Itens</span><strong><?= $data['done'] ?>/<?= $data['total'] ?> concluídos</strong></div>
                    </div>
                    <div class="ac-footer">
                        <span class="progress-pct"><?= $progress ?>%</span>
                        <div class="progress-track">
                            <div class="progress-bar" style="width:<?= $progress ?>%"></div>
                        </div>
                    </div>
                </div>
                <div class="ac-actions">
                    <?php if ($progress >= 100): ?>
                    <form method="post" onclick="event.stopPropagation()">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="finalize_audit">
                        <input type="hidden" name="audit_id" value="<?= $id ?>">
                        <button type="submit" class="btn-finalize">✓ Finalizar</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" onclick="event.stopPropagation()">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_audit">
                        <input type="hidden" name="audit_id" value="<?= $id ?>">
                        <button type="submit" class="btn-delete-card" onclick="return confirm('Excluir a auditoria Linha <?= h(addslashes($audit['linha'])) ?> – <?= h(addslashes($audit['cliente'])) ?>? Todos os itens serão removidos.')">🗑 Deletar</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

</main>

<!-- ========================================================
     MODAL: Nova Auditoria
     ======================================================== -->
<div class="modal" id="modal-new-audit" role="dialog" aria-modal="true">
    <div class="modal-backdrop" onclick="closeModal('modal-new-audit')"></div>
    <div class="modal-box modal-form">
        <div class="modal-head">
            <h2>Nova Auditoria</h2>
            <button class="btn-close" onclick="closeModal('modal-new-audit')" aria-label="Fechar">&times;</button>
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create_audit">
            <input type="hidden" name="audit_date" value="<?= date('Y-m-d') ?>">

            <label>Linha *
                <input type="text" name="linha" placeholder="Ex: 10" required>
            </label>
            <label>Cliente *
                <input type="text" name="client_name" placeholder="Ex: GM" required>
            </label>
            <label>Criador da auditoria *
                <input type="text" name="audit_owner" placeholder="Ex: Jose" required>
            </label>
            <label>Data limite *
                <input type="date" name="deadline_date" id="audit_deadline_date" required>
            </label>
            <div class="form-footer span2">
                <button type="button" class="btn-secondary" onclick="closeModal('modal-new-audit')">Cancelar</button>
                <button type="submit">Criar</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAIS: Kanban por Auditoria
     ======================================================== -->
<?php foreach ($audits as $audit):
    $id       = (int) $audit['id'];
    $data     = $auditData[$id];
    $progress = $data['progress'];
    $grouped  = $data['grouped'];
?>
<div class="modal modal-fullscreen" id="modal-audit-<?= $id ?>" role="dialog" aria-modal="true">
    <div class="modal-backdrop" onclick="closeModal('modal-audit-<?= $id ?>')"></div>
    <div class="modal-box modal-kanban-box">

        <div class="modal-head">
            <div class="mk-title">
                <h2>Linha <?= h($audit['linha']) ?> &ndash; <?= h($audit['cliente']) ?></h2>
                <div class="mk-meta">
                    <span>Responsável: <strong><?= h($audit['responsavel']) ?></strong></span>
                    <span>Prazo: <strong><?= h(formatDate($audit['data_limite'])) ?></strong></span>
                    <span>Progresso: <strong><?= $data['done'] ?>/<?= $data['total'] ?> (<?= $progress ?>%)</strong></span>
                </div>
                <div class="progress-track mk-progress">
                    <div class="progress-bar" style="width:<?= $progress ?>%"></div>
                </div>
            </div>
            <div class="mk-actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="load_template_items">
                    <input type="hidden" name="audit_id" value="<?= $id ?>">
                    <button type="submit" class="btn-sm btn-create-auditoria">Criar AUDITORIA</button>
                </form>
                <button class="btn-sm btn-secondary" onclick="openNewItemModal(<?= $id ?>, '<?= h($audit['data_limite']) ?>')">+ Item</button>
                <button class="btn-close" onclick="closeModal('modal-audit-<?= $id ?>')" aria-label="Fechar">&times;</button>
            </div>
        </div>

        <div class="board" data-audit-id="<?= $id ?>" data-csrf="<?= h($csrf) ?>" data-deadline="<?= h($audit['data_limite']) ?>">
            <?php foreach ($statusColumns as $statusKey => $statusLabel): ?>
            <div class="column" data-status="<?= h($statusKey) ?>">
                <div class="col-head">
                    <h3><?= h($statusLabel) ?></h3>
                    <span class="col-count"><?= count($grouped[$statusKey]) ?></span>
                </div>
                <div class="cards" data-status="<?= h($statusKey) ?>">
                    <?php foreach ($grouped[$statusKey] as $item):
                        $stateClass = visualState($item, $today);
                    ?>
                    <article class="card <?= h($stateClass) ?>"
                        draggable="true"
                        data-item-id="<?= (int) $item['id'] ?>"
                        data-audit-id="<?= $id ?>"
                        data-title="<?= h($item['titulo']) ?>"
                        data-responsible="<?= h($item['responsavel']) ?>"
                        data-planned-date="<?= h($item['data_prevista']) ?>"
                        data-priority="<?= h($item['prioridade']) ?>"
                        data-status="<?= h($item['status']) ?>"
                        data-notes="<?= h((string) ($item['observacao'] ?? '')) ?>"
                        data-arquivo-pdf="<?= h((string) ($item['arquivo_pdf'] ?? '')) ?>"
                        data-audit-pdf="<?= h((string) ($audit['arquivo_pdf'] ?? '')) ?>"
                        data-deadline="<?= h($audit['data_limite']) ?>"
                        onclick="openItemModal(this)">
                        <div class="card-top">
                            <span class="card-title"><?= h($item['titulo']) ?></span>
                            <div style="display:flex;gap:4px;align-items:center">
                                <?php $pdfPath = (string) ($item['arquivo_pdf'] ?? $audit['arquivo_pdf'] ?? ''); ?>
                                <?php if ($pdfPath !== ''): ?>
                                    <a href="<?= h($pdfPath) ?>" target="_blank" title="Ver PDF" style="text-decoration:none;color:#d97706;font-size:16px;padding:2px 4px" onclick="event.stopPropagation()">📄</a>
                                <?php endif; ?>
                                <span class="priority p-<?= h($item['prioridade']) ?>"><?= strtoupper(h($item['prioridade'])) ?></span>
                            </div>
                        </div>
                        <?php if (!empty($item['categoria'])): ?>
                            <small class="tag"><?= h($item['categoria']) ?></small>
                        <?php endif; ?>
                        <div class="card-meta">
                            <span>&#128100; <?= h($item['responsavel']) ?></span>
                            <span>&#128197; <?= h(formatDate($item['data_prevista'])) ?></span>
                        </div>
                        <?php if (!empty($item['observacao'])): ?>
                            <p class="card-notes"><?= h($item['observacao']) ?></p>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>
<?php endforeach; ?>

<!-- ========================================================
     MODAL: Novo Item
     ======================================================== -->
<div class="modal" id="modal-new-item" role="dialog" aria-modal="true">
    <div class="modal-backdrop" onclick="closeModal('modal-new-item')"></div>
    <div class="modal-box modal-form">
        <div class="modal-head">
            <h2>Novo Item</h2>
            <button class="btn-close" onclick="closeModal('modal-new-item')" aria-label="Fechar">&times;</button>
        </div>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create_item">
            <input type="hidden" name="audit_id" id="new-item-audit-id">

            <label class="span2">Título *
                <input type="text" name="title" required>
            </label>
            <label>Categoria
                <input type="text" name="category" placeholder="Opcional">
            </label>
            <label>Responsável *
                <input type="text" name="responsible" required>
            </label>
            <label>Término previsto *
                <input type="date" name="planned_end_date" id="new-item-date" required>
            </label>
            <label>Prioridade
                <select name="priority">
                    <option value="baixa">Baixa</option>
                    <option value="media" selected>Média</option>
                    <option value="alta">Alta</option>
                </select>
            </label>
            <label class="span2">Observação
                <input type="text" name="notes" placeholder="Opcional">
            </label>
            <div class="form-footer span2">
                <button type="button" class="btn-secondary" onclick="closeModal('modal-new-item')">Cancelar</button>
                <button type="submit">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL: Edição do Item
     ======================================================== -->
<div class="modal" id="modal-item" role="dialog" aria-modal="true">
    <div class="modal-backdrop" onclick="closeModal('modal-item')"></div>
    <div class="modal-box modal-form">
        <div class="modal-head">
            <h2 id="modal-item-title" class="item-title-heading">Item</h2>
            <button class="btn-close" onclick="closeModal('modal-item')" aria-label="Fechar">&times;</button>
        </div>
        <form method="post" class="form-grid" id="form-item-edit" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_item_meta">
            <input type="hidden" name="audit_id" id="edit-audit-id">
            <input type="hidden" name="item_id" id="edit-item-id">

            <label>Responsável *
                <input type="text" name="responsible" id="edit-responsible" required>
            </label>
            <label>Término previsto *
                <input type="date" name="planned_end_date" id="edit-planned-date" required>
            </label>
            <label>Prioridade
                <select name="priority" id="edit-priority">
                    <option value="baixa">Baixa</option>
                    <option value="media">Média</option>
                    <option value="alta">Alta</option>
                </select>
            </label>
            <label>Status
                <select name="status" id="edit-status">
                    <?php foreach ($statusColumns as $value => $label): ?>
                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span2">Observação
                <textarea name="notes" id="edit-notes" rows="3" placeholder="Opcional"></textarea>
            </label>
            <div id="pdf-viewer" class="span2 pdf-viewer" style="display:none">
                <div class="pdf-viewer-head">
                    <strong>PDF atual</strong>
                    <a id="pdf-link" href="#" target="_blank" class="btn-secondary btn-sm pdf-view-button" onclick="event.preventDefault();window.open(this.href, '_blank')">Visualizar PDF</a>
                </div>
                <label class="pdf-replace-toggle">
                    <input type="checkbox" id="replace-pdf" name="replace-pdf">
                    <span>Substituir PDF existente?</span>
                </label>
                <input type="file" name="arquivo_pdf" accept="application/pdf" id="pdf-file-input" style="display:none;margin-top:8px">
            </div>
            <label class="span2" id="pdf-upload-label">Arquivo PDF (opcional)
                <input type="file" name="arquivo_pdf" accept="application/pdf" id="pdf-file-main">
                <small>Apenas PDFs. Deixe em branco para manter o arquivo atual.</small>
            </label>
            <div class="form-footer span2">
                <button type="button" class="btn-danger-outline" id="btn-delete-item">Excluir item</button>
                <div style="display:flex;gap:8px;margin-left:auto">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-item')">Cancelar</button>
                    <button type="submit">Salvar</button>
                </div>
            </div>
        </form>
        <form method="post" id="form-item-delete" style="display:none">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete_item">
            <input type="hidden" name="audit_id" id="del-audit-id">
            <input type="hidden" name="item_id" id="del-item-id">
        </form>
    </div>
</div>

<script>
    window.__AUTO_OPEN__ = <?= $autoOpenAuditId ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
