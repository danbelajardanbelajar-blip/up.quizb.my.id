<?php
// ============================================
// api/category.php — Category Endpoints
// ============================================

function category_list(): void {
    $categories = DB::all(
        "SELECT c.id, c.name, c.slug, c.description, c.icon, c.color, c.quiz_count
         FROM categories c
         ORDER BY c.quiz_count DESC, c.name ASC"
    );
    jsonSuccess($categories);
}

function category_get(): void {
    $slug = sanitizeString($_GET['slug'] ?? '');
    $id   = (int)($_GET['id'] ?? 0);

    if ($slug) {
        $cat = DB::one('SELECT * FROM categories WHERE slug = ?', [$slug]);
    } elseif ($id) {
        $cat = DB::one('SELECT * FROM categories WHERE id = ?', [$id]);
    } else {
        jsonError('Slug atau ID diperlukan');
    }

    if (!$cat) jsonError('Kategori tidak ditemukan', 404);
    jsonSuccess($cat);
}
