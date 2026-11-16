-- the label written on the red "rejected" arrow of a workflow step is editable
ALTER TABLE workflow_steps
  ADD COLUMN reject_label_en VARCHAR(60) NULL AFTER reject_to_position,
  ADD COLUMN reject_label_ar VARCHAR(60) NULL AFTER reject_label_en;
