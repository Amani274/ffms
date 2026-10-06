-- 003_production.sql: crops, pests, irrigation, livestock, storage, harvest

CREATE TABLE crops (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  variety VARCHAR(100) NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'cereal',
  days_to_maturity INT NOT NULL DEFAULT 120,
  expected_yield_kg_ha DECIMAL(10,2) NOT NULL DEFAULT 0,
  seed_rate_kg_ha DECIMAL(8,2) NULL,
  plant_spacing_cm VARCHAR(40) NULL,
  plants_per_ha INT NULL,
  water_need_mm INT NOT NULL DEFAULT 500,
  optimal_ph_min DECIMAL(3,1) NOT NULL DEFAULT 5.8,
  optimal_ph_max DECIMAL(3,1) NOT NULL DEFAULT 7.0,
  optimal_temp_min DECIMAL(4,1) NOT NULL DEFAULT 15,
  optimal_temp_max DECIMAL(4,1) NOT NULL DEFAULT 30,
  unit VARCHAR(10) NOT NULL DEFAULT 'kg',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_crop_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plantings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  crop_id INT UNSIGNED NOT NULL,
  season VARCHAR(20) NOT NULL,
  planting_date DATE NOT NULL,
  area_ha DECIMAL(10,2) NOT NULL,
  seed_quantity_kg DECIMAL(10,2) NULL,
  spacing_cm VARCHAR(40) NULL,
  density_per_ha INT NULL,
  expected_harvest_date DATE NOT NULL,
  growth_stage VARCHAR(20) NOT NULL DEFAULT 'planted',
  status VARCHAR(15) NOT NULL DEFAULT 'growing',
  expected_yield_kg DECIMAL(12,2) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pl_status (farm_id, status),
  CONSTRAINT fk_pl_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_crop FOREIGN KEY (crop_id) REFERENCES crops(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE crop_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  planting_id INT UNSIGNED NOT NULL,
  activity_type VARCHAR(20) NOT NULL,
  activity_date DATE NOT NULL,
  scheduled TINYINT(1) NOT NULL DEFAULT 0,
  item_id INT UNSIGNED NULL,
  product_name VARCHAR(150) NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit VARCHAR(20) NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  posted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_ca_date (activity_date),
  CONSTRAINT fk_ca_pl FOREIGN KEY (planting_id) REFERENCES plantings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ca_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE growth_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  planting_id INT UNSIGNED NOT NULL,
  record_date DATE NOT NULL,
  growth_stage VARCHAR(20) NOT NULL,
  height_cm DECIMAL(6,1) NULL,
  health_score TINYINT NOT NULL DEFAULT 80,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_gr_pl FOREIGN KEY (planting_id) REFERENCES plantings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pest_catalog (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'pest',
  affects VARCHAR(190) NULL,
  symptoms TEXT NULL,
  control_measures TEXT NULL,
  CONSTRAINT fk_pc_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE scouting_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_id INT UNSIGNED NOT NULL,
  planting_id INT UNSIGNED NULL,
  catalog_id INT UNSIGNED NULL,
  report_date DATE NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'low',
  affected_area_pct DECIMAL(5,1) NOT NULL DEFAULT 0,
  status VARCHAR(15) NOT NULL DEFAULT 'open',
  reported_by INT UNSIGNED NULL,
  notes TEXT NULL,
  CONSTRAINT fk_sr_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_pl FOREIGN KEY (planting_id) REFERENCES plantings(id) ON DELETE SET NULL,
  CONSTRAINT fk_sr_cat FOREIGN KEY (catalog_id) REFERENCES pest_catalog(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE treatments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scouting_id INT UNSIGNED NOT NULL,
  treatment_date DATE NOT NULL,
  item_id INT UNSIGNED NULL,
  chemical_name VARCHAR(150) NOT NULL,
  dosage VARCHAR(80) NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  effectiveness TINYINT NULL,
  notes VARCHAR(255) NULL,
  posted TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_tr_sr FOREIGN KEY (scouting_id) REFERENCES scouting_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE water_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  source_type VARCHAR(20) NOT NULL,
  capacity_litres BIGINT NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'active',
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_ws_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE irrigation_systems (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NULL,
  water_source_id INT UNSIGNED NULL,
  pump_equipment_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  system_type VARCHAR(15) NOT NULL,
  flow_rate_lph INT NOT NULL DEFAULT 0,
  status VARCHAR(15) NOT NULL DEFAULT 'operational',
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_is_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE,
  CONSTRAINT fk_is_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE SET NULL,
  CONSTRAINT fk_is_ws FOREIGN KEY (water_source_id) REFERENCES water_sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_is_eq FOREIGN KEY (pump_equipment_id) REFERENCES equipment(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE irrigation_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_id INT UNSIGNED NOT NULL,
  scheduled_date DATE NOT NULL,
  start_time TIME NOT NULL DEFAULT '06:00:00',
  duration_min INT NOT NULL,
  planned_volume_litres BIGINT NOT NULL DEFAULT 0,
  actual_volume_litres BIGINT NULL,
  energy_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'planned',
  notes VARCHAR(255) NULL,
  KEY idx_isch_date (scheduled_date),
  CONSTRAINT fk_isch_sys FOREIGN KEY (system_id) REFERENCES irrigation_systems(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE animals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  tag_no VARCHAR(40) NOT NULL,
  species VARCHAR(20) NOT NULL,
  breed VARCHAR(80) NULL,
  sex VARCHAR(1) NOT NULL DEFAULT 'F',
  birth_date DATE NULL,
  acquired_date DATE NULL,
  source VARCHAR(80) NULL,
  current_weight_kg DECIMAL(8,2) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  death_date DATE NULL,
  death_cause VARCHAR(190) NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_animal_tag (farm_id, tag_no),
  CONSTRAINT fk_an_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE animal_health (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  animal_id INT UNSIGNED NOT NULL,
  record_type VARCHAR(15) NOT NULL,
  record_date DATE NOT NULL,
  name VARCHAR(150) NOT NULL,
  item_id INT UNSIGNED NULL,
  next_due_date DATE NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  vet_name VARCHAR(120) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_ah_an FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE CASCADE,
  CONSTRAINT fk_ah_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE animal_weights (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  animal_id INT UNSIGNED NOT NULL,
  weigh_date DATE NOT NULL,
  weight_kg DECIMAL(8,2) NOT NULL,
  CONSTRAINT fk_aw_an FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE feed_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  animal_id INT UNSIGNED NULL,
  group_name VARCHAR(80) NULL,
  item_id INT UNSIGNED NULL,
  feed_date DATE NOT NULL,
  quantity_kg DECIMAL(10,2) NOT NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  posted TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_fr_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr_an FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE SET NULL,
  CONSTRAINT fk_fr_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE breeding_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dam_id INT UNSIGNED NOT NULL,
  sire_id INT UNSIGNED NULL,
  mating_date DATE NOT NULL,
  expected_birth_date DATE NULL,
  actual_birth_date DATE NULL,
  offspring_count INT NOT NULL DEFAULT 0,
  status VARCHAR(15) NOT NULL DEFAULT 'pregnant',
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_br_dam FOREIGN KEY (dam_id) REFERENCES animals(id) ON DELETE CASCADE,
  CONSTRAINT fk_br_sire FOREIGN KEY (sire_id) REFERENCES animals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE livestock_production (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  animal_id INT UNSIGNED NULL,
  species VARCHAR(20) NOT NULL,
  product VARCHAR(20) NOT NULL,
  production_date DATE NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'litres',
  CONSTRAINT fk_lp_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE,
  CONSTRAINT fk_lp_an FOREIGN KEY (animal_id) REFERENCES animals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE warehouses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farm_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  warehouse_type VARCHAR(10) NOT NULL DEFAULT 'dry',
  capacity_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
  temperature_c DECIMAL(4,1) NULL,
  location VARCHAR(150) NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'active',
  CONSTRAINT fk_wh_farm FOREIGN KEY (farm_id) REFERENCES farms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE harvests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  planting_id INT UNSIGNED NOT NULL,
  harvest_date DATE NOT NULL,
  quantity_kg DECIMAL(12,2) NOT NULL,
  grade VARCHAR(5) NOT NULL DEFAULT 'A',
  quality_notes VARCHAR(255) NULL,
  loss_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
  loss_reason VARCHAR(120) NULL,
  post_harvest_handling VARCHAR(190) NULL,
  packaging VARCHAR(80) NULL,
  warehouse_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hv_pl FOREIGN KEY (planting_id) REFERENCES plantings(id) ON DELETE CASCADE,
  CONSTRAINT fk_hv_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE storage_lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT UNSIGNED NOT NULL,
  harvest_id INT UNSIGNED NULL,
  crop_id INT UNSIGNED NOT NULL,
  lot_code VARCHAR(40) NOT NULL,
  quantity_kg DECIMAL(12,2) NOT NULL,
  grade VARCHAR(5) NOT NULL DEFAULT 'A',
  stored_date DATE NOT NULL,
  max_storage_days INT NOT NULL DEFAULT 90,
  spoilage_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'stored',
  UNIQUE KEY uq_lot_code (lot_code),
  CONSTRAINT fk_sl_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
  CONSTRAINT fk_sl_hv FOREIGN KEY (harvest_id) REFERENCES harvests(id) ON DELETE SET NULL,
  CONSTRAINT fk_sl_crop FOREIGN KEY (crop_id) REFERENCES crops(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
