<?php
/**
 * Shared Footer Layout
 * St. Jude High School Inventory Management System
 */
?>
        </main>
        
        <!-- Bottom Footer -->
        <footer class="py-3 px-4 bg-white border-top text-muted small d-flex flex-column flex-sm-row justify-content-between align-items-center">
            <div>
                &copy; <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong>. Built with Core PHP & MySQL.
            </div>
            <div class="mt-2 mt-sm-0">
                <span class="badge bg-secondary me-2">Phase 1 Active</span>
                <span>Currency: <strong>KSh</strong></span>
            </div>
        </footer>
    </div>
</div>

<!-- jQuery 3.7.1 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

<!-- Bootstrap 5.3.3 Bundle JS (with Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Main Application JS -->
<script src="<?= e(url('public/assets/js/main.js')) ?>"></script>
</body>
</html>
