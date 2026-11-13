<?php
/*
 * Ufuq — reusable confirmation modal.
 *
 * Usage (in any page, after bootstrap.php so e() and csrf_token() exist):
 *
 *   require_once __DIR__ . '/../includes/confirm-modal.php';
 *
 *   // 1) Render the modal once, OUTSIDE any animated/transformed section:
 *   confirm_modal([
 *       'id'            => 'deleteOppModal',
 *       'title'         => 'Delete Opportunity?',
 *       'message'       => 'This opportunity will be permanently deleted. This cannot be undone.',
 *       'confirm_label' => 'Yes, Delete',
 *       'icon'          => 'trash',              // trash | lock | alert | check
 *       'variant'       => 'danger',             // danger (red, default) | primary (purple, for positive actions)
 *       'fields'        => ['action' => 'delete_opportunity', 'tab' => 'opportunities'],
 *       'id_field'      => 'opportunity_id',     // filled when the modal is opened
 *   ]);
 *
 *   // 2) Open it from a button:
 *   <button type="button" onclick="openConfirmModal('deleteOppModal', 42)">Delete</button>
 *
 * Behaviour:
 *   - No dark background behind the box.
 *   - Until the user picks Cancel or the confirm button, clicking anywhere else or pressing Esc
 *     makes the box shake and glow (focus effect), and the page behind can't be scrolled.
 *   - Tab key stays inside the modal.
 */

if (!function_exists('confirm_modal')) {

    function confirm_modal_icon(string $name): string
    {
        $icons = [
            'trash' => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
            'lock'  => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/>',
            'check' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/>',
        ];
        $paths = $icons[$name] ?? $icons['alert'];
        return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }

    function confirm_modal_assets(): void
    {
        ?>
<style>
/* ===== Ufuq confirmation modal (includes/confirm-modal.php) ===== */
.ufuq-confirm-overlay{position:fixed;inset:0;z-index:10000;display:none;justify-content:center;align-items:center;padding:20px;background:transparent}
.ufuq-confirm-overlay.is-open{display:flex}
.ufuq-confirm-box{width:100%;max-width:440px;padding:30px;border:1px solid #ece6f2;border-radius:18px;background:#fff;color:#281a46;text-align:center;
  box-shadow:0 24px 60px rgba(40,26,70,.18),0 4px 14px rgba(40,26,70,.08);animation:ufuqConfirmIn .2s ease-out}
.ufuq-confirm-icon{display:flex;align-items:center;justify-content:center;width:58px;height:58px;margin:0 auto 16px;border-radius:50%;background:#fff1f2;color:#b42318}
.ufuq-confirm-box h2{margin:0 0 12px;color:#39265f;font-size:1.35rem}
.ufuq-confirm-message{margin:0;color:#29243a;line-height:1.8}
.ufuq-confirm-actions{display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap;margin-top:26px}
.ufuq-confirm-actions form{margin:0}
.ufuq-confirm-actions button{min-width:120px;min-height:42px}
.ufuq-confirm-submit{border:none;border-radius:8px;padding:10px 16px;background:#b42318;color:#fff;font-weight:700;cursor:pointer}
.ufuq-confirm-submit:hover{background:#912018}
/* Purple variant for positive actions (e.g. Approve) */
.ufuq-confirm-overlay.is-primary .ufuq-confirm-icon{background:#f0ebf8;color:#39265f}
.ufuq-confirm-overlay.is-primary .ufuq-confirm-submit{background:#39265f}
.ufuq-confirm-overlay.is-primary .ufuq-confirm-submit:hover{background:#281a46}
.ufuq-confirm-overlay.is-primary .ufuq-confirm-box.is-attention{box-shadow:0 0 0 4px rgba(57,38,95,.18),0 24px 60px rgba(40,26,70,.22)}
/* Focus effect: shown when the user clicks outside / presses Esc before choosing */
.ufuq-confirm-box.is-attention{animation:ufuqConfirmShake .45s ease both}
.ufuq-confirm-box.is-attention{box-shadow:0 0 0 4px rgba(180,35,24,.18),0 24px 60px rgba(40,26,70,.22)}
@keyframes ufuqConfirmIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
@keyframes ufuqConfirmShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-9px)}40%{transform:translateX(8px)}60%{transform:translateX(-6px)}80%{transform:translateX(4px)}}
body.ufuq-confirm-lock{overflow:hidden}
@media(prefers-reduced-motion:reduce){.ufuq-confirm-box,.ufuq-confirm-box.is-attention{animation:none}}
</style>
<script>
(function () {
    function box(overlay) { return overlay.querySelector('.ufuq-confirm-box'); }

    function attention(overlay) {
        var b = box(overlay);
        b.classList.remove('is-attention');
        void b.offsetWidth;                 // restart the animation
        b.classList.add('is-attention');
        var first = overlay.querySelector('[data-confirm-cancel]');
        if (first) first.focus();
    }

    window.openConfirmModal = function (modalId, value) {
        var overlay = document.getElementById(modalId);
        if (!overlay) return;
        var idInput = overlay.querySelector('[data-confirm-id]');
        if (idInput && value !== undefined) idInput.value = value;
        overlay.classList.add('is-open');
        document.body.classList.add('ufuq-confirm-lock');
        var cancel = overlay.querySelector('[data-confirm-cancel]');
        if (cancel) cancel.focus();
    };

    window.closeConfirmModal = function (modalId) {
        var overlay = document.getElementById(modalId);
        if (!overlay) return;
        overlay.classList.remove('is-open');
        box(overlay).classList.remove('is-attention');
        document.body.classList.remove('ufuq-confirm-lock');
    };

    document.addEventListener('click', function (ev) {
        var overlay = ev.target.closest && ev.target.closest('.ufuq-confirm-overlay.is-open');
        if (overlay && !ev.target.closest('.ufuq-confirm-box')) attention(overlay);
    });

    document.addEventListener('keydown', function (ev) {
        var overlay = document.querySelector('.ufuq-confirm-overlay.is-open');
        if (!overlay) return;
        if (ev.key === 'Escape') { ev.preventDefault(); attention(overlay); return; }
        if (ev.key === 'Tab') {                 // keep keyboard focus inside the modal
            var items = overlay.querySelectorAll('button, [href], input:not([type="hidden"])');
            if (!items.length) return;
            var first = items[0], last = items[items.length - 1];
            if (!overlay.contains(document.activeElement)) { ev.preventDefault(); first.focus(); }
            else if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
            else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
        }
    });
})();
</script>
        <?php
    }

    function confirm_modal(array $o): void
    {
        static $assetsPrinted = false;
        if (!$assetsPrinted) { $assetsPrinted = true; confirm_modal_assets(); }

        $id      = (string)($o['id'] ?? 'confirmModal');
        $title   = (string)($o['title'] ?? 'Are you sure?');
        $message = (string)($o['message'] ?? '');
        $label   = (string)($o['confirm_label'] ?? 'Confirm');
        $icon    = (string)($o['icon'] ?? 'alert');
        $fields  = (array)($o['fields'] ?? []);
        $idField = $o['id_field'] ?? null;
        $variant = ($o['variant'] ?? 'danger') === 'primary' ? ' is-primary' : '';
        ?>
<div id="<?= e($id) ?>" class="ufuq-confirm-overlay<?= $variant ?>" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>Title">
  <div class="ufuq-confirm-box">
    <div class="ufuq-confirm-icon"><?= confirm_modal_icon($icon) ?></div>
    <h2 id="<?= e($id) ?>Title"><?= e($title) ?></h2>
    <?php if ($message !== ''): ?><p class="ufuq-confirm-message"><?= e($message) ?></p><?php endif; ?>
    <div class="ufuq-confirm-actions">
      <button type="button" class="btn btn-ghost" data-confirm-cancel onclick="closeConfirmModal('<?= e($id) ?>')">Cancel</button>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php foreach ($fields as $name => $value): ?>
          <input type="hidden" name="<?= e((string)$name) ?>" value="<?= e((string)$value) ?>">
        <?php endforeach; ?>
        <?php if ($idField): ?>
          <input type="hidden" name="<?= e((string)$idField) ?>" value="" data-confirm-id>
        <?php endif; ?>
        <button type="submit" class="ufuq-confirm-submit"><?= e($label) ?></button>
      </form>
    </div>
  </div>
</div>
        <?php
    }
}