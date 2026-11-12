-- Working-day leave (annual, sick) excludes weekends and public holidays.
-- Calendar-day leave (maternity, paternity) counts every date in the range.

ALTER TABLE leave_types
  ADD COLUMN day_basis VARCHAR(20) NOT NULL DEFAULT 'working' AFTER accrual_start_months;

UPDATE leave_types
SET day_basis = 'calendar'
WHERE name IN ('Maternity leave', 'Paternity leave');

UPDATE leave_types
SET day_basis = 'working'
WHERE name IN ('Annual leave', 'Sick leave', 'Compassionate leave');

UPDATE leave_types
SET day_basis = 'working'
WHERE day_basis NOT IN ('working', 'calendar');