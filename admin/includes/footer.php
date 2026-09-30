<?php

/** Admin layout — bottom part. Closes what header.php opened. */
?>
</main>
</div>
</div>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/jquery.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/dataTables.min.js')) ?>"></script>
<script src="<?= e(adminAsset('vendor/datatables/dataTables.bootstrap5.min.js')) ?>"></script>
<script src="<?= e(adminAsset('admin.js')) ?>"></script>
<?php foreach ($page_scripts ?? [] as $page_script): // a page's own script, e.g. $page_scripts = ['order_create.js'] ?>
    <script src="<?= e(adminAsset($page_script)) ?>"></script>
<?php endforeach; ?>
</body>

</html>