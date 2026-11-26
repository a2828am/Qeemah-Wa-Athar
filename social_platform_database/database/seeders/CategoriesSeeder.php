<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/CategoriesSeeder.php
 *
 * 30 تصنيفًا رئيسيًا في جدول categories، جميعها بـ parent_category_id = NULL
 * كما ينص database/seeders/README.md.
 *
 * مفتاح منع التكرار: category_code (UNIQUE)
 */

declare(strict_types=1);

/**
 * @return array<int, array{name:string, code:string, image:string}>
 */
function seed_categories_data(): array
{
    return [
        ['name' => 'إلكترونيات وأجهزة',       'code' => 'electronics',        'image' => 'storage/categories/electronics.png'],
        ['name' => 'هواتف وأجهزة لوحية',       'code' => 'phones_tablets',     'image' => 'storage/categories/phones_tablets.png'],
        ['name' => 'حاسبات وملحقاتها',         'code' => 'computers',          'image' => 'storage/categories/computers.png'],
        ['name' => 'أثاث ومستلزمات منزلية',    'code' => 'furniture',          'image' => 'storage/categories/furniture.png'],
        ['name' => 'أجهزة منزلية',             'code' => 'home_appliances',    'image' => 'storage/categories/home_appliances.png'],
        ['name' => 'ملابس وأحذية',             'code' => 'clothing',           'image' => 'storage/categories/clothing.png'],
        ['name' => 'مستلزمات أطفال',           'code' => 'baby_children',      'image' => 'storage/categories/baby_children.png'],
        ['name' => 'كتب ومراجع',               'code' => 'books',              'image' => 'storage/categories/books.png'],
        ['name' => 'قرطاسية ومستلزمات مكتبية', 'code' => 'stationery',         'image' => 'storage/categories/stationery.png'],
        ['name' => 'أدوات وعدد يدوية',         'code' => 'tools',              'image' => 'storage/categories/tools.png'],
        ['name' => 'مواد بناء وترميم',         'code' => 'building_materials', 'image' => 'storage/categories/building_materials.png'],
        ['name' => 'أجهزة طبية ومستلزمات صحية','code' => 'medical',           'image' => 'storage/categories/medical.png'],
        ['name' => 'أثاث مكتبي',               'code' => 'office_furniture',   'image' => 'storage/categories/office_furniture.png'],
        ['name' => 'دراجات ووسائل نقل',        'code' => 'bikes_transport',    'image' => 'storage/categories/bikes_transport.png'],
        ['name' => 'قطع غيار وإكسسوارات',      'code' => 'spare_parts',        'image' => 'storage/categories/spare_parts.png'],
        ['name' => 'ألعاب وألغاز',             'code' => 'toys_games',         'image' => 'storage/categories/toys_games.png'],
        ['name' => 'رياضة ولياقة',             'code' => 'sports_fitness',     'image' => 'storage/categories/sports_fitness.png'],
        ['name' => 'أدوات مطبخ',               'code' => 'kitchenware',        'image' => 'storage/categories/kitchenware.png'],
        ['name' => 'مواد غذائية تموينية',      'code' => 'food_supplies',      'image' => 'storage/categories/food_supplies.png'],
        ['name' => 'أدوات زراعية',             'code' => 'agriculture',        'image' => 'storage/categories/agriculture.png'],
        ['name' => 'حيوانات ومستلزماتها',      'code' => 'animals',            'image' => 'storage/categories/animals.png'],
        ['name' => 'مستلزمات مدرسية وتعليمية', 'code' => 'education',          'image' => 'storage/categories/education.png'],
        ['name' => 'أجهزة كهربائية ومولدات',   'code' => 'electric_generators','image' => 'storage/categories/electric_generators.png'],
        ['name' => 'مياه وصرف صحي',            'code' => 'water_sanitation',   'image' => 'storage/categories/water_sanitation.png'],
        ['name' => 'مستلزمات مخيمات وإيواء',   'code' => 'shelter_camps',      'image' => 'storage/categories/shelter_camps.png'],
        ['name' => 'أثاث ومستلزمات مساجد',     'code' => 'mosque_supplies',    'image' => 'storage/categories/mosque_supplies.png'],
        ['name' => 'هدايا ومستلزمات مناسبات',  'code' => 'gifts_occasions',    'image' => 'storage/categories/gifts_occasions.png'],
        ['name' => 'حرف يدوية وإنتاج منزلي',   'code' => 'handicrafts',        'image' => 'storage/categories/handicrafts.png'],
        ['name' => 'خدمات ومهن',               'code' => 'services',           'image' => 'storage/categories/services.png'],
        ['name' => 'أخرى',                     'code' => 'other',              'image' => 'storage/categories/other.png'],
    ];
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_categories(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    foreach (seed_categories_data() as $index => $category) {
        $row = [
            'parent_category_id' => null, // جميعها رئيسية عند البداية
            'category_name'      => $category['name'],
            'category_code'      => $category['code'],
            'description'        => 'تصنيف رئيسي: ' . $category['name'],
            'image'              => $category['image'],
            'display_order'      => $index + 1,
            'status'             => 1,
        ];

        $result = seed_upsert($db, 'categories', $row, ['category_code']);
        match ($result['action']) {
            'inserted' => $inserted++,
            'updated'  => $updated++,
            default    => $unchanged++,
        };
    }

    return [$inserted, $updated, $unchanged];
}
