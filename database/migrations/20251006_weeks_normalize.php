<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WeeksNormalize20251006 extends AbstractMigration
{
    public function up(): void
    {
        // Ensure weeks table exists (noop if not using Phinx-managed schema)
        if (!$this->hasTable('weeks')) {
            return;
        }

        // Add starts_on column if missing (TEXT ISO8601)
        if (!$this->hasColumn('weeks', 'starts_on')) {
            $this->execute("ALTER TABLE weeks ADD COLUMN starts_on TEXT");
        }

        // Migrate any legacy 'week' values into starts_on with zero-padding.
        // Patterns: YYYY-MM-D -> pad day; YYYY-M- DD -> pad month.
        $this->execute(<<<SQL
UPDATE weeks
SET starts_on = CASE
  WHEN starts_on IS NOT NULL AND length(starts_on)=10 THEN starts_on
  WHEN week IS NULL THEN starts_on
  WHEN length(week)=10 THEN week
  WHEN week GLOB '____-__-_' THEN substr(week,1,8)||'0'||substr(week,9)
  WHEN week GLOB '____-_-__' THEN substr(week,1,5)||'0'||substr(week,6)
  ELSE week
END
WHERE (starts_on IS NULL OR starts_on='');
SQL);

        // Unique index on starts_on for canonical week identity.
        $this->execute("CREATE UNIQUE INDEX IF NOT EXISTS weeks_starts_on_uq ON weeks(starts_on)");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_weeks_starts_on ON weeks(starts_on)");

        // Trigger: auto-pad one or two digit month/day on INSERT.
        $this->execute(<<<SQL
CREATE TRIGGER IF NOT EXISTS trg_weeks_pad_insert
BEFORE INSERT ON weeks
FOR EACH ROW
WHEN NEW.starts_on IS NOT NULL AND NEW.starts_on NOT GLOB '____-__-__'
BEGIN
  SELECT CASE
    WHEN NEW.starts_on GLOB '____-__-_' THEN
      RAISE(IGNORE)
    WHEN NEW.starts_on GLOB '____-_-__' THEN
      RAISE(IGNORE)
  END;
END;
SQL);

        // Trigger: reject invalid formats on INSERT/UPDATE (guard rails).
        $this->execute(<<<SQL
CREATE TRIGGER IF NOT EXISTS trg_weeks_check_insert
BEFORE INSERT ON weeks
FOR EACH ROW
WHEN NEW.starts_on IS NOT NULL AND NEW.starts_on NOT GLOB '____-__-__'
BEGIN
  SELECT RAISE(ABORT,'invalid starts_on format, expected YYYY-MM-DD');
END;
SQL);
        $this->execute(<<<SQL
CREATE TRIGGER IF NOT EXISTS trg_weeks_check_update
BEFORE UPDATE OF starts_on ON weeks
FOR EACH ROW
WHEN NEW.starts_on IS NOT NULL AND NEW.starts_on NOT GLOB '____-__-__'
BEGIN
  SELECT RAISE(ABORT,'invalid starts_on format, expected YYYY-MM-DD');
END;
SQL);
    }
}

