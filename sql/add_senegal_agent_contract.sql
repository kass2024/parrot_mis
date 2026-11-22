ALTER TABLE `agent_contracts`
  ADD COLUMN IF NOT EXISTS `contract_template` varchar(24) NOT NULL DEFAULT 'standard',
  ADD COLUMN IF NOT EXISTS `senegal_details` json DEFAULT NULL;
