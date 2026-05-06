<?php
// ============================================
// api/category_group.php — Category Group (Rumpun) Endpoints
// ============================================

function category_group_list(): void {
    $groups = DB::all(
        "SELECT g.id, g.name, g.icon, g.color, g.description, g.order_num,
                COUNT(c.id) AS category_count
         FROM category_groups g
         LEFT JOIN categories c ON c.group_id = g.id
         GROUP BY g.id
         ORDER BY g.order_num, g.id"
    );
    foreach ($groups as &$g) {
        $g['id']             = (int)$g['id'];
        $g['order_num']      = (int)$g['order_num'];
        $g['category_count'] = (int)$g['category_count'];
        $g['categories']     = DB::all(
            "SELECT id, name, slug, icon, color, quiz_count
             FROM categories WHERE group_id = ? ORDER BY name",
            [$g['id']]
        );
        foreach ($g['categories'] as &$c) {
            $c['id']         = (int)$c['id'];
            $c['quiz_count'] = (int)$c['quiz_count'];
        }
        unset($c);
    }
    unset($g);
    jsonSuccess($groups);
}
