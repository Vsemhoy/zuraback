<?php

namespace Database\Seeders;

use App\Models\ThingType;
use Illuminate\Database\Seeder;

class EquipTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'computer' => ['Компьютер', [['key' => 'cpu', 'label' => 'Процессор', 'type' => 'text'], ['key' => 'ram', 'label' => 'Оперативная память', 'type' => 'number', 'unit' => 'ГБ'], ['key' => 'storage', 'label' => 'Накопитель', 'type' => 'text']]],
            'workplace' => ['Рабочее место', []],
            'laptop' => ['Ноутбук', [['key' => 'cpu', 'label' => 'Процессор', 'type' => 'text'], ['key' => 'ram', 'label' => 'Оперативная память', 'type' => 'number', 'unit' => 'ГБ']]],
            'monitor' => ['Монитор', [['key' => 'diagonal', 'label' => 'Диагональ', 'type' => 'number', 'unit' => 'дюйм'], ['key' => 'resolution', 'label' => 'Разрешение', 'type' => 'text']]],
            'component' => ['Комплектующее', []],
            'peripheral' => ['Периферия', []],
            'office' => ['Оргтехника', []],
            'network' => ['Сетевое оборудование', []],
            'appliance' => ['Бытовая техника', []],
            'furniture' => ['Мебель', []],
            'tool' => ['Инструмент', []],
        ];
        foreach ($types as $code => [$name, $schema]) {
            ThingType::firstOrCreate(['code' => $code], ['name' => $name, 'scope_id' => null, 'schema' => $schema]);
        }
    }
}
