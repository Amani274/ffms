<?php

declare(strict_types=1);

/**
 * Single source of truth for REST resources: columns + validation, role access,
 * farm scoping, joins and display columns. Consumed by Crud and exposed to the UI via /meta.
 *
 *  scope   SQL expression (table alias `t`) that resolves to the owning farm id
 *  own     SQL condition restricting the `worker` role to its own rows (uses :uid)
 *  ops     allowed operations (list,get,create,update,delete); default all
 *  protect SQL condition a row must satisfy to be edited/deleted (e.g. manual entries only)
 *  extra   accepted-but-not-stored input keys (used by hooks)
 */
final class Registry
{
    private static ?array $all = null;

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    private static function build(): array
    {
        $ALL = Auth::ROLES;
        $ADMIN = ['admin', 'owner'];
        $OPS = ['admin', 'owner', 'manager'];
        $AGRO = ['admin', 'owner', 'manager', 'agronomist'];
        $FIELD = ['admin', 'owner', 'manager', 'agronomist', 'worker'];
        $FIN = ['admin', 'owner', 'accountant'];
        $FINR = ['admin', 'owner', 'accountant', 'manager'];
        $NOWORK = ['admin', 'owner', 'manager', 'agronomist', 'accountant'];
        $DEL = ['admin', 'owner', 'manager'];

        $r = [];

        // ---------- 1. Farms & fields ----------
        $r['farms'] = [
            'table' => 'farms', 'label' => 'Farm', 'scope' => 't.id', 'order' => 't.name',
            'read' => $ALL, 'write' => $ADMIN, 'delete' => ['admin'],
            'search' => ['t.name', 't.location'],
            'cols' => [
                'name' => 'str:150!', 'location' => 'str:190', 'latitude' => 'dec:-90,90', 'longitude' => 'dec:-180,180',
                'size_ha' => 'dec:0,1000000!', 'registration_no' => 'str:60', 'contact_phone' => 'str:40',
                'description' => 'text', 'status' => 'enum:active,inactive|default:active',
            ],
        ];
        $r['fields'] = [
            'table' => 'fields', 'label' => 'Field', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'select' => "t.*, (SELECT c.name FROM plantings p JOIN crops c ON c.id = p.crop_id WHERE p.field_id = t.id AND p.status = 'growing' ORDER BY p.planting_date DESC LIMIT 1) AS current_crop",
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL,
            'search' => ['t.name', 't.soil_type'], 'filters' => ['status', 'land_use'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:120!', 'size_ha' => 'dec:0.01,100000!',
                'latitude' => 'dec:-90,90', 'longitude' => 'dec:-180,180', 'boundary_geojson' => 'text',
                'soil_type' => 'str:60', 'soil_ph' => 'dec:2,11', 'organic_matter_pct' => 'dec:0,100',
                'nitrogen_ppm' => 'dec:0,10000', 'phosphorus_ppm' => 'dec:0,10000', 'potassium_ppm' => 'dec:0,10000',
                'soil_notes' => 'text', 'land_use' => 'enum:cropping,pasture,orchard,fallow,infrastructure|default:cropping',
                'status' => 'enum:active,fallow,retired|default:active',
            ],
        ];
        $r['field_history'] = [
            'table' => 'field_history', 'label' => 'Field history', 'scope' => 'fl.farm_id', 'order' => 't.created_at DESC, t.id DESC',
            'join' => 'JOIN fields fl ON fl.id = t.field_id', 'select' => 't.*, fl.name AS field_name',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'filters' => ['field_id'],
            'cols' => [
                'field_id' => 'int!|fk:fields', 'season' => 'str:20!', 'crop_name' => 'str:100!',
                'yield_kg_per_ha' => 'dec:0,1000000', 'notes' => 'text',
            ],
        ];

        // ---------- 2. Crops ----------
        $r['crops'] = [
            'table' => 'crops', 'label' => 'Crop', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name, t.variety',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'search' => ['t.name', 't.variety'], 'filters' => ['category'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:100!', 'variety' => 'str:100',
                'category' => 'enum:cereal,legume,vegetable,fruit,oilseed,root,fodder,other|default:cereal',
                'days_to_maturity' => 'int:1,1500!', 'expected_yield_kg_ha' => 'dec:0,1000000!', 'seed_rate_kg_ha' => 'dec:0,100000',
                'plant_spacing_cm' => 'str:40', 'plants_per_ha' => 'int:0,10000000', 'water_need_mm' => 'int:0,5000|default:500',
                'optimal_ph_min' => 'dec:2,11|default:5.8', 'optimal_ph_max' => 'dec:2,11|default:7.0',
                'optimal_temp_min' => 'dec:-10,50|default:15', 'optimal_temp_max' => 'dec:-10,60|default:30',
                'unit' => 'str:10|default:kg', 'notes' => 'text',
            ],
        ];
        $r['plantings'] = [
            'table' => 'plantings', 'label' => 'Planting', 'scope' => 't.farm_id', 'farm_col' => 'farm_id',
            'order' => "FIELD(t.status,'growing','planned','harvested','failed'), t.planting_date DESC",
            'join' => 'JOIN fields fl ON fl.id = t.field_id JOIN crops cr ON cr.id = t.crop_id',
            'select' => 't.*, fl.name AS field_name, cr.name AS crop_name, cr.variety AS crop_variety, DATEDIFF(t.expected_harvest_date, CURDATE()) AS days_to_harvest',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL,
            'search' => ['cr.name', 'fl.name', 't.season'],
            'filters' => ['field_id' => 't.field_id', 'crop_id' => 't.crop_id', 'status' => 't.status', 'season' => 't.season'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'field_id' => 'int!|fk:fields', 'crop_id' => 'int!|fk:crops', 'season' => 'str:20!',
                'planting_date' => 'date!', 'area_ha' => 'dec:0.01,100000!', 'seed_quantity_kg' => 'dec:0,1000000',
                'spacing_cm' => 'str:40', 'density_per_ha' => 'int:0,10000000', 'expected_harvest_date' => 'date',
                'growth_stage' => 'enum:planned,planted,germination,vegetative,flowering,fruiting,maturity,harvested|default:planted',
                'status' => 'enum:planned,growing,harvested,failed|default:growing',
                'expected_yield_kg' => 'dec:0,1000000000', 'notes' => 'text',
            ],
        ];
        $plantingJoin = 'JOIN plantings pl ON pl.id = t.planting_id JOIN fields fl ON fl.id = pl.field_id JOIN crops cr ON cr.id = pl.crop_id';
        $r['crop_activities'] = [
            'redact' => ['worker' => ['cost']],
            'table' => 'crop_activities', 'label' => 'Crop activity', 'scope' => 'pl.farm_id', 'order' => 't.activity_date DESC, t.id DESC',
            'join' => $plantingJoin, 'select' => 't.*, cr.name AS crop_name, fl.name AS field_name, ii.name AS item_name',
            'join_extra' => 'LEFT JOIN inventory_items ii ON ii.id = t.item_id',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['planting_id', 'activity_type', 'scheduled', 'field_id' => 'pl.field_id'],
            'date_col' => 't.activity_date',
            'cols' => [
                'planting_id' => 'int!|fk:plantings',
                'activity_type' => 'enum:fertilizer,manure,spraying,irrigation,weeding,scouting,pruning,other!',
                'activity_date' => 'date!', 'scheduled' => 'bool|default:0', 'item_id' => 'int|fk:inventory_items',
                'product_name' => 'str:150', 'quantity' => 'dec:0,10000000|default:0', 'unit' => 'str:20',
                'cost' => 'dec:0,1000000000|default:0', 'notes' => 'str:255',
            ],
        ];
        $r['growth_records'] = [
            'table' => 'growth_records', 'label' => 'Growth record', 'scope' => 'pl.farm_id', 'order' => 't.record_date DESC, t.id DESC',
            'join' => $plantingJoin, 'select' => 't.*, cr.name AS crop_name, fl.name AS field_name',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['planting_id'],
            'cols' => [
                'planting_id' => 'int!|fk:plantings', 'record_date' => 'date!',
                'growth_stage' => 'enum:planned,planted,germination,vegetative,flowering,fruiting,maturity,harvested!',
                'height_cm' => 'dec:0,10000', 'health_score' => 'int:0,100|default:80', 'notes' => 'str:255',
            ],
        ];
        $r['harvests'] = [
            'table' => 'harvests', 'label' => 'Harvest', 'scope' => 'pl.farm_id', 'order' => 't.harvest_date DESC, t.id DESC',
            'join' => $plantingJoin . ' LEFT JOIN warehouses wh ON wh.id = t.warehouse_id',
            'select' => 't.*, cr.name AS crop_name, fl.name AS field_name, wh.name AS warehouse_name, ROUND(t.quantity_kg - t.loss_kg, 2) AS net_kg, ROUND((t.quantity_kg - t.loss_kg) / NULLIF(pl.area_ha,0), 1) AS yield_kg_ha',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['planting_id', 'grade'], 'date_col' => 't.harvest_date',
            'extra' => ['mark_complete'],
            'cols' => [
                'planting_id' => 'int!|fk:plantings', 'harvest_date' => 'date!', 'quantity_kg' => 'dec:0,1000000000!',
                'grade' => 'enum:A,B,C,reject|default:A', 'quality_notes' => 'str:255', 'loss_kg' => 'dec:0,1000000000|default:0',
                'loss_reason' => 'str:120', 'post_harvest_handling' => 'str:190', 'packaging' => 'str:80', 'warehouse_id' => 'int|fk:warehouses',
            ],
        ];

        // ---------- 9. Pests & diseases ----------
        $r['pest_catalog'] = [
            'table' => 'pest_catalog', 'label' => 'Pest / disease', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.kind, t.name',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'search' => ['t.name', 't.affects'], 'filters' => ['kind'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:120!', 'kind' => 'enum:pest,disease,weed|default:pest',
                'affects' => 'str:190', 'symptoms' => 'text', 'control_measures' => 'text',
            ],
        ];
        $r['scouting_reports'] = [
            'table' => 'scouting_reports', 'label' => 'Scouting report', 'scope' => 'fl.farm_id', 'order' => 't.report_date DESC, t.id DESC',
            'join' => 'JOIN fields fl ON fl.id = t.field_id LEFT JOIN pest_catalog pc ON pc.id = t.catalog_id LEFT JOIN plantings pl ON pl.id = t.planting_id LEFT JOIN crops cr ON cr.id = pl.crop_id',
            'select' => 't.*, fl.name AS field_name, pc.name AS pest_name, pc.kind AS pest_kind, cr.name AS crop_name, pc.control_measures',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['field_id', 'status', 'severity'], 'date_col' => 't.report_date',
            'cols' => [
                'field_id' => 'int!|fk:fields', 'planting_id' => 'int|fk:plantings', 'catalog_id' => 'int|fk:pest_catalog',
                'report_date' => 'date!', 'severity' => 'enum:low,medium,high,critical|default:low',
                'affected_area_pct' => 'dec:0,100|default:0', 'status' => 'enum:open,treating,resolved|default:open', 'notes' => 'text',
            ],
        ];
        $r['treatments'] = [
            'redact' => ['worker' => ['cost']],
            'table' => 'treatments', 'label' => 'Treatment', 'scope' => 'fl.farm_id', 'order' => 't.treatment_date DESC, t.id DESC',
            'join' => 'JOIN scouting_reports sr ON sr.id = t.scouting_id JOIN fields fl ON fl.id = sr.field_id LEFT JOIN pest_catalog pc ON pc.id = sr.catalog_id',
            'select' => 't.*, fl.name AS field_name, pc.name AS pest_name',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['scouting_id'], 'date_col' => 't.treatment_date',
            'cols' => [
                'scouting_id' => 'int!|fk:scouting_reports', 'treatment_date' => 'date!', 'item_id' => 'int|fk:inventory_items',
                'chemical_name' => 'str:150!', 'dosage' => 'str:80', 'quantity' => 'dec:0,10000000|default:0',
                'cost' => 'dec:0,1000000000|default:0', 'effectiveness' => 'int:1,10', 'notes' => 'str:255',
            ],
        ];

        // ---------- 4. Irrigation ----------
        $r['water_sources'] = [
            'table' => 'water_sources', 'label' => 'Water source', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'filters' => ['source_type', 'status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:120!', 'source_type' => 'enum:borehole,river,dam,canal,well,rainwater,municipal!',
                'capacity_litres' => 'int:0,1000000000000', 'status' => 'enum:active,low,dry,inactive|default:active', 'notes' => 'str:255',
            ],
        ];
        $r['irrigation_systems'] = [
            'table' => 'irrigation_systems', 'label' => 'Irrigation system', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'join' => 'LEFT JOIN fields fl ON fl.id = t.field_id LEFT JOIN water_sources ws ON ws.id = t.water_source_id LEFT JOIN equipment eq ON eq.id = t.pump_equipment_id',
            'select' => 't.*, fl.name AS field_name, ws.name AS source_name, eq.name AS pump_name',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'filters' => ['status', 'system_type', 'field_id' => 't.field_id'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'field_id' => 'int|fk:fields', 'water_source_id' => 'int|fk:water_sources',
                'pump_equipment_id' => 'int|fk:equipment', 'name' => 'str:120!', 'system_type' => 'enum:drip,sprinkler,pivot,flood,furrow,manual!',
                'flow_rate_lph' => 'int:0,100000000|default:0', 'status' => 'enum:operational,maintenance,fault,offline|default:operational', 'notes' => 'str:255',
            ],
        ];
        $r['irrigation_schedules'] = [
            'redact' => ['worker' => ['energy_cost']],
            'table' => 'irrigation_schedules', 'label' => 'Irrigation schedule', 'scope' => 's.farm_id', 'order' => 't.scheduled_date DESC, t.start_time',
            'join' => 'JOIN irrigation_systems s ON s.id = t.system_id LEFT JOIN fields fl ON fl.id = s.field_id',
            'select' => 't.*, s.name AS system_name, fl.name AS field_name, s.flow_rate_lph',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['system_id', 'status'], 'date_col' => 't.scheduled_date',
            'cols' => [
                'system_id' => 'int!|fk:irrigation_systems', 'scheduled_date' => 'date!', 'start_time' => 'time|default:06:00:00',
                'duration_min' => 'int:1,100000!', 'planned_volume_litres' => 'int:0,1000000000000|default:0',
                'actual_volume_litres' => 'int:0,1000000000000', 'energy_cost' => 'dec:0,1000000000|default:0',
                'status' => 'enum:planned,done,skipped,failed|default:planned', 'notes' => 'str:255',
            ],
        ];

        // ---------- 5. Livestock ----------
        $r['animals'] = [
            'table' => 'animals', 'label' => 'Animal', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.species, t.tag_no',
            'select' => 't.*, TIMESTAMPDIFF(MONTH, t.birth_date, CURDATE()) AS age_months',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'search' => ['t.tag_no', 't.breed', 't.species'],
            'filters' => ['species', 'status', 'sex'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'tag_no' => 'str:40!', 'species' => 'enum:cattle,goat,sheep,pig,poultry,other!',
                'breed' => 'str:80', 'sex' => 'enum:M,F|default:F', 'birth_date' => 'date', 'acquired_date' => 'date',
                'source' => 'str:80', 'current_weight_kg' => 'dec:0,5000', 'status' => 'enum:active,sold,dead,culled|default:active',
                'death_date' => 'date', 'death_cause' => 'str:190', 'notes' => 'str:255',
            ],
        ];
        $animalJoin = 'JOIN animals a ON a.id = t.animal_id';
        $r['animal_health'] = [
            'redact' => ['worker' => ['cost']],
            'table' => 'animal_health', 'label' => 'Health record', 'scope' => 'a.farm_id', 'order' => 't.record_date DESC, t.id DESC',
            'join' => $animalJoin, 'select' => 't.*, a.tag_no, a.species',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'filters' => ['animal_id', 'record_type'], 'date_col' => 't.record_date',
            'cols' => [
                'animal_id' => 'int!|fk:animals', 'record_type' => 'enum:vaccination,treatment,disease,checkup!', 'record_date' => 'date!',
                'name' => 'str:150!', 'item_id' => 'int|fk:inventory_items', 'next_due_date' => 'date',
                'cost' => 'dec:0,1000000000|default:0', 'vet_name' => 'str:120', 'notes' => 'str:255',
            ],
        ];
        $r['animal_weights'] = [
            'table' => 'animal_weights', 'label' => 'Weight record', 'scope' => 'a.farm_id', 'order' => 't.weigh_date DESC, t.id DESC',
            'join' => $animalJoin, 'select' => 't.*, a.tag_no, a.species',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['animal_id'], 'date_col' => 't.weigh_date',
            'cols' => ['animal_id' => 'int!|fk:animals', 'weigh_date' => 'date!', 'weight_kg' => 'dec:0.1,5000!'],
        ];
        $r['feed_records'] = [
            'redact' => ['worker' => ['cost']],
            'table' => 'feed_records', 'label' => 'Feed record', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.feed_date DESC, t.id DESC',
            'join' => 'LEFT JOIN animals a ON a.id = t.animal_id LEFT JOIN inventory_items ii ON ii.id = t.item_id',
            'select' => 't.*, a.tag_no, ii.name AS feed_name',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['animal_id'], 'date_col' => 't.feed_date',
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'animal_id' => 'int|fk:animals', 'group_name' => 'str:80', 'item_id' => 'int|fk:inventory_items',
                'feed_date' => 'date!', 'quantity_kg' => 'dec:0.01,10000000!', 'cost' => 'dec:0,1000000000|default:0',
            ],
        ];
        $r['breeding_records'] = [
            'table' => 'breeding_records', 'label' => 'Breeding record', 'scope' => 'a.farm_id', 'order' => 't.mating_date DESC',
            'join' => 'JOIN animals a ON a.id = t.dam_id LEFT JOIN animals sr ON sr.id = t.sire_id',
            'select' => 't.*, a.tag_no AS dam_tag, sr.tag_no AS sire_tag, a.species',
            'read' => $ALL, 'write' => $AGRO, 'delete' => $DEL, 'filters' => ['status'],
            'cols' => [
                'dam_id' => 'int!|fk:animals', 'sire_id' => 'int|fk:animals', 'mating_date' => 'date!', 'expected_birth_date' => 'date',
                'actual_birth_date' => 'date', 'offspring_count' => 'int:0,100|default:0',
                'status' => 'enum:pregnant,born,failed,aborted|default:pregnant', 'notes' => 'str:255',
            ],
        ];
        $r['livestock_production'] = [
            'table' => 'livestock_production', 'label' => 'Production record', 'scope' => 't.farm_id', 'farm_col' => 'farm_id',
            'order' => 't.production_date DESC, t.id DESC', 'join' => 'LEFT JOIN animals a ON a.id = t.animal_id', 'select' => 't.*, a.tag_no',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['species', 'product'], 'date_col' => 't.production_date',
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'animal_id' => 'int|fk:animals', 'species' => 'enum:cattle,goat,sheep,pig,poultry,other!',
                'product' => 'enum:milk,eggs,meat,wool,manure,other!', 'production_date' => 'date!', 'quantity' => 'dec:0,10000000!', 'unit' => 'str:10|default:litres',
            ],
        ];

        // ---------- 6. Inventory ----------
        $r['inventory_items'] = [
            'redact' => ['worker' => ['unit_cost', 'stock_value']],
            'table' => 'inventory_items', 'label' => 'Inventory item', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.category, t.name',
            'join' => 'LEFT JOIN suppliers s ON s.id = t.supplier_id',
            'select' => 't.*, s.name AS supplier_name, (t.quantity <= t.reorder_level) AS low_stock, DATEDIFF(t.expiry_date, CURDATE()) AS days_to_expiry, ROUND(t.quantity * t.unit_cost, 2) AS stock_value',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'search' => ['t.name', 't.storage_location'], 'filters' => ['category'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:150!',
                'category' => 'enum:seed,fertilizer,chemical,feed,vet_medicine,packaging,fuel,tools,other!', 'unit' => 'str:20|default:kg',
                'quantity' => 'dec:0,1000000000|default:0', 'reorder_level' => 'dec:0,1000000000|default:0', 'unit_cost' => 'dec:0,1000000000|default:0',
                'expiry_date' => 'date', 'storage_location' => 'str:120', 'supplier_id' => 'int|fk:suppliers', 'notes' => 'text',
            ],
        ];
        $r['stock_movements'] = [
            'redact' => ['worker' => ['unit_cost']],
            'table' => 'stock_movements', 'label' => 'Stock movement', 'scope' => 'ii.farm_id', 'order' => 't.movement_date DESC, t.id DESC',
            'join' => 'JOIN inventory_items ii ON ii.id = t.item_id', 'select' => 't.*, ii.name AS item_name, ii.unit, ii.category',
            'read' => $ALL, 'write' => $FIELD, 'ops' => ['list', 'get', 'create'], 'filters' => ['item_id', 'movement_type'], 'date_col' => 't.movement_date',
            'custom_create' => true,
            'cols' => [
                'item_id' => 'int!|fk:inventory_items', 'movement_type' => 'enum:in,out!', 'quantity' => 'dec:0,1000000000!',
                'unit_cost' => 'dec:0,1000000000|default:0', 'movement_date' => 'date!', 'reference' => 'str:120', 'notes' => 'str:255',
            ],
        ];

        // ---------- 7. Equipment ----------
        $r['equipment'] = [
            'redact' => ['worker' => ['purchase_cost', 'salvage_value', 'annual_depreciation', 'book_value']],
            'table' => 'equipment', 'label' => 'Equipment', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'select' => "t.*, ROUND((t.purchase_cost - t.salvage_value) / GREATEST(t.useful_life_years,1), 2) AS annual_depreciation, ROUND(GREATEST(t.purchase_cost - ((t.purchase_cost - t.salvage_value) / GREATEST(t.useful_life_years,1)) * (IFNULL(DATEDIFF(CURDATE(), t.purchase_date),0) / 365.25), t.salvage_value), 2) AS book_value",
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'search' => ['t.name', 't.make_model', 't.serial_no'], 'filters' => ['status', 'equipment_type'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:150!',
                'equipment_type' => 'enum:tractor,implement,pump,vehicle,harvester,sprayer,generator,tool,other!', 'make_model' => 'str:120',
                'serial_no' => 'str:80', 'purchase_date' => 'date', 'purchase_cost' => 'dec:0,1000000000|default:0',
                'salvage_value' => 'dec:0,1000000000|default:0', 'useful_life_years' => 'int:1,100|default:10', 'total_hours' => 'dec:0,10000000|default:0',
                'service_interval_hours' => 'int:1,100000', 'fuel_type' => 'str:20', 'status' => 'enum:operational,maintenance,broken,retired|default:operational', 'notes' => 'text',
            ],
        ];
        $eqJoin = 'JOIN equipment e ON e.id = t.equipment_id';
        $r['equipment_allocations'] = [
            'table' => 'equipment_allocations', 'label' => 'Allocation', 'scope' => 'e.farm_id', 'order' => 't.date_from DESC',
            'join' => $eqJoin . ' LEFT JOIN fields fl ON fl.id = t.field_id LEFT JOIN workers w ON w.id = t.worker_id',
            'select' => 't.*, e.name AS equipment_name, fl.name AS field_name, w.name AS worker_name',
            'read' => $NOWORK, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['equipment_id'],
            'cols' => [
                'equipment_id' => 'int!|fk:equipment', 'field_id' => 'int|fk:fields', 'worker_id' => 'int|fk:workers',
                'date_from' => 'date!', 'date_to' => 'date', 'purpose' => 'str:190',
            ],
        ];
        $r['equipment_usage'] = [
            'redact' => ['worker' => ['fuel_cost']],
            'table' => 'equipment_usage', 'label' => 'Usage log', 'scope' => 'e.farm_id', 'order' => 't.usage_date DESC, t.id DESC',
            'join' => $eqJoin . ' LEFT JOIN workers w ON w.id = t.worker_id LEFT JOIN fields fl ON fl.id = t.field_id',
            'select' => 't.*, e.name AS equipment_name, w.name AS worker_name, fl.name AS field_name',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'filters' => ['equipment_id'], 'date_col' => 't.usage_date',
            'cols' => [
                'equipment_id' => 'int!|fk:equipment', 'usage_date' => 'date!', 'hours' => 'dec:0,24|default:0',
                'fuel_litres' => 'dec:0,100000|default:0', 'fuel_cost' => 'dec:0,1000000000|default:0',
                'worker_id' => 'int|fk:workers', 'field_id' => 'int|fk:fields', 'notes' => 'str:255',
            ],
        ];
        $r['maintenance_records'] = [
            'table' => 'maintenance_records', 'label' => 'Maintenance', 'scope' => 'e.farm_id', 'order' => "FIELD(t.status,'scheduled','done'), t.scheduled_date",
            'join' => $eqJoin, 'select' => 't.*, e.name AS equipment_name, DATEDIFF(t.scheduled_date, CURDATE()) AS days_until',
            'read' => $NOWORK, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['equipment_id', 'status', 'maintenance_type'],
            'cols' => [
                'equipment_id' => 'int!|fk:equipment', 'maintenance_type' => 'enum:service,repair,inspection!', 'scheduled_date' => 'date!',
                'completed_date' => 'date', 'cost' => 'dec:0,1000000000|default:0', 'description' => 'str:255', 'next_due_date' => 'date',
                'status' => 'enum:scheduled,done,cancelled|default:scheduled',
            ],
        ];

        // ---------- 8. Labour ----------
        $r['workers'] = [
            'table' => 'workers', 'label' => 'Worker', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'join' => 'LEFT JOIN users u ON u.id = t.user_id', 'select' => 't.*, u.email AS user_email',
            'own' => 't.user_id = :uid',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'search' => ['t.name', 't.job_title'], 'filters' => ['status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'user_id' => 'int|fk:users', 'name' => 'str:120!', 'job_title' => 'str:80!', 'phone' => 'str:40',
                'national_id' => 'str:40', 'hire_date' => 'date!', 'wage_type' => 'enum:daily,monthly,hourly|default:daily',
                'wage_rate' => 'dec:0,1000000000!', 'status' => 'enum:active,inactive,terminated|default:active',
            ],
        ];
        $wJoin = 'JOIN workers w ON w.id = t.worker_id';
        $r['worker_attendance'] = [
            'table' => 'worker_attendance', 'label' => 'Attendance', 'scope' => 'w.farm_id', 'order' => 't.work_date DESC, w.name',
            'join' => $wJoin, 'select' => 't.*, w.name AS worker_name', 'own' => 'w.user_id = :uid',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['worker_id', 'status'], 'date_col' => 't.work_date',
            'cols' => [
                'worker_id' => 'int!|fk:workers', 'work_date' => 'date!', 'status' => 'enum:present,absent,leave,half|default:present',
                'hours' => 'dec:0,24|default:8', 'notes' => 'str:190',
            ],
        ];
        $r['tasks'] = [
            'table' => 'tasks', 'label' => 'Task', 'scope' => 't.farm_id', 'farm_col' => 'farm_id',
            'order' => "FIELD(t.status,'in_progress','todo','done'), t.due_date",
            'join' => 'LEFT JOIN workers w ON w.id = t.worker_id LEFT JOIN fields fl ON fl.id = t.field_id',
            'select' => 't.*, w.name AS worker_name, fl.name AS field_name, (t.status <> \'done\' AND t.due_date < CURDATE()) AS overdue',
            'own' => 'w.user_id = :uid',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'search' => ['t.title', 'w.name'], 'filters' => ['status', 'priority', 'worker_id' => 't.worker_id', 'field_id' => 't.field_id'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'title' => 'str:190!', 'description' => 'text', 'field_id' => 'int|fk:fields', 'worker_id' => 'int|fk:workers',
                'due_date' => 'date', 'priority' => 'enum:low,medium,high|default:medium', 'status' => 'enum:todo,in_progress,done|default:todo',
                'hours_spent' => 'dec:0,10000|default:0', 'output_units' => 'dec:0,10000000|default:0',
            ],
        ];
        $r['worker_reviews'] = [
            'table' => 'worker_reviews', 'label' => 'Performance review', 'scope' => 'w.farm_id', 'order' => 't.review_date DESC',
            'join' => $wJoin, 'select' => 't.*, w.name AS worker_name', 'own' => 'w.user_id = :uid',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['worker_id'],
            'cols' => ['worker_id' => 'int!|fk:workers', 'review_date' => 'date!', 'rating' => 'int:1,5!', 'comments' => 'str:255'],
        ];
        $r['payroll_runs'] = [
            'table' => 'payroll_runs', 'label' => 'Payroll', 'scope' => 'w.farm_id', 'order' => 't.period_end DESC, w.name',
            'join' => $wJoin, 'select' => 't.*, w.name AS worker_name, w.job_title', 'own' => 'w.user_id = :uid',
            'read' => ['admin', 'owner', 'manager', 'accountant', 'worker'], 'write' => $FIN, 'delete' => ['admin', 'owner'],
            'filters' => ['worker_id', 'status'],
            'cols' => [
                'worker_id' => 'int!|fk:workers', 'period_start' => 'date!', 'period_end' => 'date!', 'days_worked' => 'dec:0,366|default:0',
                'gross_pay' => 'dec:0,1000000000|default:0', 'deductions' => 'dec:0,1000000000|default:0', 'net_pay' => 'dec:0,1000000000|default:0',
                'status' => 'enum:draft,paid|default:draft',
            ],
        ];

        // ---------- 14. Suppliers & procurement ----------
        $r['suppliers'] = [
            'table' => 'suppliers', 'label' => 'Supplier', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'select' => "t.*, (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = t.id) AS order_count, (SELECT IFNULL(SUM(po.total_amount),0) FROM purchase_orders po WHERE po.supplier_id = t.id AND po.status <> 'cancelled') AS total_purchased",
            'read' => $NOWORK, 'write' => $OPS, 'delete' => $DEL, 'search' => ['t.name', 't.contact_person', 't.category'], 'filters' => ['status', 'category'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:150!', 'category' => 'str:40|default:general', 'contact_person' => 'str:120',
                'phone' => 'str:40', 'email' => 'email', 'address' => 'str:255', 'rating' => 'dec:0,5|default:3', 'status' => 'enum:active,inactive|default:active',
            ],
        ];
        $r['quotations'] = [
            'table' => 'quotations', 'label' => 'Quotation', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.id DESC',
            'join' => 'JOIN suppliers s ON s.id = t.supplier_id', 'select' => 't.*, s.name AS supplier_name, ROUND(t.quantity * t.unit_price, 2) AS total',
            'read' => $FINR, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['supplier_id' => 't.supplier_id', 'status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'supplier_id' => 'int!|fk:suppliers', 'description' => 'str:190!', 'quantity' => 'dec:0.01,1000000000!',
                'unit_price' => 'dec:0,1000000000!', 'valid_until' => 'date', 'status' => 'enum:open,accepted,rejected,expired|default:open',
            ],
        ];
        $r['purchase_orders'] = [
            'table' => 'purchase_orders', 'label' => 'Purchase order', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.order_date DESC, t.id DESC',
            'join' => 'JOIN suppliers s ON s.id = t.supplier_id',
            'select' => 't.*, s.name AS supplier_name, (t.total_amount - t.amount_paid) AS balance, (SELECT COUNT(*) FROM purchase_order_items i WHERE i.po_id = t.id) AS item_count',
            'read' => $FINR, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['status', 'supplier_id' => 't.supplier_id'], 'search' => ['t.po_no', 's.name'],
            'date_col' => 't.order_date', 'custom_create' => true, 'extra' => ['items'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'supplier_id' => 'int!|fk:suppliers', 'po_no' => 'str:30', 'order_date' => 'date!',
                'expected_date' => 'date', 'notes' => 'str:255',
            ],
        ];
        $r['purchase_order_items'] = [
            'table' => 'purchase_order_items', 'label' => 'PO line', 'scope' => 'po.farm_id', 'order' => 't.id',
            'join' => 'JOIN purchase_orders po ON po.id = t.po_id LEFT JOIN inventory_items ii ON ii.id = t.item_id',
            'select' => 't.*, po.po_no, ii.name AS item_name, ROUND(t.quantity * t.unit_price, 2) AS line_total',
            'read' => $FINR, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['po_id'],
            'cols' => [
                'po_id' => 'int!|fk:purchase_orders', 'item_id' => 'int|fk:inventory_items', 'description' => 'str:190!',
                'quantity' => 'dec:0.01,1000000000!', 'unit_price' => 'dec:0,1000000000!',
            ],
        ];

        // ---------- 15. Storage & post-harvest ----------
        $r['warehouses'] = [
            'table' => 'warehouses', 'label' => 'Warehouse', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'select' => "t.*, (SELECT IFNULL(SUM(l.quantity_kg),0) FROM storage_lots l WHERE l.warehouse_id = t.id AND l.status IN ('stored','partial')) AS stored_kg",
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['warehouse_type', 'status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:120!', 'warehouse_type' => 'enum:dry,cold,silo,open|default:dry',
                'capacity_kg' => 'dec:0,1000000000|default:0', 'temperature_c' => 'dec:-40,60', 'location' => 'str:150', 'status' => 'enum:active,maintenance,inactive|default:active',
            ],
        ];
        $r['storage_lots'] = [
            'redact' => ['worker' => ['unit_value', 'lot_value']],
            'table' => 'storage_lots', 'label' => 'Storage lot', 'scope' => 'w.farm_id', 'order' => 't.stored_date DESC, t.id DESC',
            'join' => 'JOIN warehouses w ON w.id = t.warehouse_id JOIN crops cr ON cr.id = t.crop_id',
            'select' => 't.*, w.name AS warehouse_name, cr.name AS crop_name, DATEDIFF(CURDATE(), t.stored_date) AS days_stored, ROUND(t.quantity_kg * t.unit_value, 2) AS lot_value',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['warehouse_id', 'crop_id' => 't.crop_id', 'status' => 't.status'],
            'cols' => [
                'warehouse_id' => 'int!|fk:warehouses', 'harvest_id' => 'int|fk:harvests', 'crop_id' => 'int!|fk:crops', 'lot_code' => 'str:40',
                'quantity_kg' => 'dec:0,1000000000!', 'grade' => 'enum:A,B,C,reject|default:A', 'stored_date' => 'date!',
                'max_storage_days' => 'int:1,3650|default:90', 'spoilage_kg' => 'dec:0,1000000000|default:0', 'unit_value' => 'dec:0,1000000|default:0',
                'status' => 'enum:stored,partial,dispatched,spoiled|default:stored',
            ],
        ];
        $r['dispatches'] = [
            'table' => 'dispatches', 'label' => 'Dispatch', 'scope' => 'w.farm_id', 'order' => 't.dispatch_date DESC, t.id DESC',
            'join' => 'JOIN storage_lots l ON l.id = t.lot_id JOIN warehouses w ON w.id = l.warehouse_id JOIN crops cr ON cr.id = l.crop_id LEFT JOIN sales_orders so ON so.id = t.order_id',
            'select' => 't.*, l.lot_code, cr.name AS crop_name, so.order_no',
            'read' => $ALL, 'write' => $OPS, 'delete' => $DEL, 'filters' => ['lot_id', 'status'], 'date_col' => 't.dispatch_date',
            'cols' => [
                'lot_id' => 'int!|fk:storage_lots', 'order_id' => 'int|fk:sales_orders', 'dispatch_date' => 'date!', 'quantity_kg' => 'dec:0.01,1000000000!',
                'vehicle' => 'str:60', 'destination' => 'str:150', 'status' => 'enum:dispatched,delivered,returned|default:dispatched',
            ],
        ];

        // ---------- 12. Market & sales ----------
        $r['customers'] = [
            'table' => 'customers', 'label' => 'Customer / buyer', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.name',
            'select' => "t.*, (SELECT IFNULL(SUM(o.total_amount),0) FROM sales_orders o WHERE o.customer_id = t.id AND o.status <> 'cancelled') AS total_sales",
            'read' => $FINR, 'write' => ['admin', 'owner', 'manager', 'accountant'], 'delete' => $DEL, 'search' => ['t.name', 't.contact_person'], 'filters' => ['customer_type', 'status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'name' => 'str:150!', 'customer_type' => 'enum:buyer,market,contract,retail,export|default:buyer',
                'contact_person' => 'str:120', 'phone' => 'str:40', 'email' => 'email', 'address' => 'str:255', 'market_destination' => 'str:120',
                'status' => 'enum:active,inactive|default:active',
            ],
        ];
        $r['market_prices'] = [
            'table' => 'market_prices', 'label' => 'Market price', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.price_date DESC, t.id DESC',
            'join' => 'JOIN crops cr ON cr.id = t.crop_id', 'select' => 't.*, cr.name AS crop_name',
            'read' => $NOWORK, 'write' => ['admin', 'owner', 'manager', 'agronomist', 'accountant'], 'delete' => $DEL, 'filters' => ['crop_id' => 't.crop_id', 'market_name'], 'date_col' => 't.price_date',
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'crop_id' => 'int!|fk:crops', 'market_name' => 'str:100!', 'price_date' => 'date!',
                'price_per_kg' => 'dec:0,1000000!', 'demand_level' => 'enum:low,medium,high|default:medium',
            ],
        ];
        $r['contracts'] = [
            'table' => 'contracts', 'label' => 'Contract', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.end_date DESC',
            'join' => 'LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN suppliers s ON s.id = t.supplier_id LEFT JOIN crops cr ON cr.id = t.crop_id',
            'select' => 't.*, cu.name AS customer_name, s.name AS supplier_name, cr.name AS crop_name',
            'read' => $FINR, 'write' => ['admin', 'owner', 'manager', 'accountant'], 'delete' => $DEL, 'filters' => ['status', 'contract_type'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'customer_id' => 'int|fk:customers', 'supplier_id' => 'int|fk:suppliers',
                'contract_type' => 'enum:offtake,contract_farming,supply,lease|default:offtake', 'crop_id' => 'int|fk:crops',
                'quantity_kg' => 'dec:0,1000000000', 'price_per_kg' => 'dec:0,1000000', 'start_date' => 'date!', 'end_date' => 'date!',
                'terms' => 'text', 'status' => 'enum:draft,active,completed,terminated|default:active',
            ],
        ];
        $r['sales_orders'] = [
            'table' => 'sales_orders', 'label' => 'Sales order', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.order_date DESC, t.id DESC',
            'join' => 'JOIN customers cu ON cu.id = t.customer_id',
            'select' => "t.*, cu.name AS customer_name, (SELECT i.invoice_no FROM invoices i WHERE i.order_id = t.id LIMIT 1) AS invoice_no, (SELECT i.status FROM invoices i WHERE i.order_id = t.id LIMIT 1) AS invoice_status",
            'read' => $FINR, 'write' => ['admin', 'owner', 'manager', 'accountant'], 'delete' => $DEL, 'search' => ['t.order_no', 'cu.name'],
            'filters' => ['status', 'customer_id' => 't.customer_id'], 'date_col' => 't.order_date', 'custom_create' => true, 'extra' => ['items'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'customer_id' => 'int!|fk:customers', 'contract_id' => 'int|fk:contracts', 'order_no' => 'str:30',
                'order_date' => 'date!', 'delivery_date' => 'date', 'status' => 'enum:pending,confirmed,delivered,cancelled|default:pending', 'notes' => 'str:255',
            ],
        ];
        $r['sales_order_items'] = [
            'table' => 'sales_order_items', 'label' => 'Order line', 'scope' => 'o.farm_id', 'order' => 't.id',
            'join' => 'JOIN sales_orders o ON o.id = t.order_id JOIN crops cr ON cr.id = t.crop_id',
            'select' => 't.*, o.order_no, cr.name AS crop_name, ROUND(t.quantity_kg * t.unit_price, 2) AS line_total',
            'read' => $FINR, 'write' => ['admin', 'owner', 'manager', 'accountant'], 'delete' => $DEL, 'filters' => ['order_id'],
            'cols' => [
                'order_id' => 'int!|fk:sales_orders', 'crop_id' => 'int!|fk:crops', 'lot_id' => 'int|fk:storage_lots',
                'quantity_kg' => 'dec:0.01,1000000000!', 'unit_price' => 'dec:0,1000000!', 'grade' => 'enum:A,B,C,reject|default:A',
            ],
        ];
        $r['invoices'] = [
            'table' => 'invoices', 'label' => 'Invoice', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.issue_date DESC, t.id DESC',
            'join' => 'JOIN sales_orders o ON o.id = t.order_id JOIN customers cu ON cu.id = o.customer_id',
            'select' => "t.*, o.order_no, cu.name AS customer_name, ROUND(t.amount - t.amount_paid, 2) AS balance, (t.status <> 'paid' AND t.due_date < CURDATE()) AS overdue",
            'read' => $FINR, 'write' => $FIN, 'ops' => ['list', 'get', 'update', 'delete'], 'delete' => ['admin', 'owner'], 'search' => ['t.invoice_no', 'cu.name'],
            'filters' => ['status', 'order_id' => 't.order_id'], 'date_col' => 't.issue_date',
            'cols' => ['due_date' => 'date', 'status' => 'enum:unpaid,partial,paid,void'],
        ];
        $r['payments'] = [
            'table' => 'payments', 'label' => 'Payment', 'scope' => 'i.farm_id', 'order' => 't.payment_date DESC, t.id DESC',
            'join' => 'JOIN invoices i ON i.id = t.invoice_id', 'select' => 't.*, i.invoice_no',
            'read' => $FINR, 'write' => $FIN, 'delete' => ['admin', 'owner'], 'ops' => ['list', 'get', 'create', 'delete'], 'filters' => ['invoice_id'], 'date_col' => 't.payment_date',
            'cols' => [
                'invoice_id' => 'int!|fk:invoices', 'payment_date' => 'date!', 'amount' => 'dec:0.01,1000000000!',
                'method' => 'enum:cash,bank,mobile_money,cheque|default:bank', 'reference' => 'str:80',
            ],
        ];

        // ---------- 13. Finance ----------
        $r['transactions'] = [
            'table' => 'transactions', 'label' => 'Transaction', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.txn_date DESC, t.id DESC',
            'join' => 'LEFT JOIN crops cr ON cr.id = t.crop_id LEFT JOIN fields fl ON fl.id = t.field_id',
            'select' => 't.*, cr.name AS crop_name, fl.name AS field_name, (t.ref_type IS NULL) AS manual',
            'read' => $FINR, 'write' => $FIN, 'delete' => ['admin', 'owner', 'accountant'], 'protect' => 't.ref_type IS NULL',
            'search' => ['t.description', 't.category'], 'filters' => ['txn_type', 'category', 'crop_id' => 't.crop_id', 'field_id' => 't.field_id'], 'date_col' => 't.txn_date',
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'txn_type' => 'enum:income,expense!',
                'category' => 'enum:sales,input,labour,fuel,equipment,loan,utilities,procurement,livestock,other!', 'amount' => 'dec:0.01,100000000000!',
                'txn_date' => 'date!', 'description' => 'str:255', 'field_id' => 'int|fk:fields', 'crop_id' => 'int|fk:crops', 'planting_id' => 'int|fk:plantings',
            ],
        ];
        $r['budgets'] = [
            'table' => 'budgets', 'label' => 'Budget', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.budget_year DESC, t.category',
            'select' => "t.*, (SELECT IFNULL(SUM(x.amount),0) FROM transactions x WHERE x.farm_id = t.farm_id AND x.txn_type = 'expense' AND x.category = t.category AND YEAR(x.txn_date) = t.budget_year) AS spent",
            'read' => $FINR, 'write' => $FIN, 'delete' => ['admin', 'owner'], 'filters' => ['budget_year'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'budget_year' => 'int:2000,2100!',
                'category' => 'enum:sales,input,labour,fuel,equipment,loan,utilities,procurement,livestock,other!', 'amount' => 'dec:0,100000000000!',
            ],
        ];
        $r['loans'] = [
            'table' => 'loans', 'label' => 'Loan', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.end_date',
            'read' => $FINR, 'write' => $FIN, 'delete' => ['admin', 'owner'], 'filters' => ['status'],
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'lender' => 'str:150!', 'purpose' => 'str:190', 'principal' => 'dec:0.01,100000000000!',
                'interest_rate_pct' => 'dec:0,200|default:0', 'start_date' => 'date!', 'end_date' => 'date!', 'balance' => 'dec:0,100000000000',
                'status' => 'enum:active,paid,defaulted|default:active',
            ],
        ];

        // ---------- 10. Weather ----------
        $r['weather_records'] = [
            'table' => 'weather_records', 'label' => 'Weather record', 'scope' => 't.farm_id', 'farm_col' => 'farm_id', 'order' => 't.record_date DESC',
            'read' => $ALL, 'write' => $FIELD, 'delete' => $DEL, 'date_col' => 't.record_date',
            'cols' => [
                'farm_id' => 'int!|fk:farms', 'record_date' => 'date!', 'temp_min_c' => 'dec:-30,60!', 'temp_max_c' => 'dec:-30,65!',
                'humidity_pct' => 'dec:0,100!', 'rainfall_mm' => 'dec:0,1000|default:0', 'wind_kph' => 'dec:0,400|default:0', 'conditions' => 'str:40',
            ],
        ];

        // ---------- 19. Alerts & notifications ----------
        $r['alerts'] = [
            'hide' => ['worker' => "t.alert_type <> 'market_opportunity'"],
            'table' => 'alerts', 'label' => 'Alert', 'scope' => 't.farm_id', 'order' => "FIELD(t.severity,'critical','warning','info'), t.created_at DESC",
            'read' => $ALL, 'write' => $ALL, 'ops' => ['list', 'get', 'update'], 'filters' => ['status', 'severity', 'alert_type'],
            'cols' => ['status' => 'enum:new,read,resolved'],
        ];

        // ---------- 21. Security ----------
        $r['audit_logs'] = [
            'table' => 'audit_logs', 'label' => 'Audit log', 'scope' => null, 'order' => 't.id DESC',
            'read' => $ADMIN, 'write' => [], 'ops' => ['list', 'get'], 'search' => ['t.user_name', 't.entity', 't.action'], 'filters' => ['entity', 'action', 'user_id'],
            'date_col' => 't.created_at', 'cols' => [],
        ];

        return $r;
    }
}
