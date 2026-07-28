<nav>
    <ul class="pagination">
        <?php foreach ($pages as $page): ?>
            <?php if ($page->separator): ?>
                <li class="page-item disabled" aria-disabled="true"><span class="page-link"><?= htmlspecialchars((string) $page->name, ENT_QUOTES) ?></span></li>
            <?php elseif ($page->current): ?>
                <li class="page-item active"><span class="page-link"><?= htmlspecialchars((string) $page->name, ENT_QUOTES) ?></span></li>
            <?php else: ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($page->url, ENT_QUOTES) ?>"><?= htmlspecialchars((string) $page->name, ENT_QUOTES) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</nav>
