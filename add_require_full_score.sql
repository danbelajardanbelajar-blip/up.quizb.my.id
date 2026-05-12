-- Migration: add require_full_score to assignments
ALTER TABLE `assignments`
  ADD COLUMN `require_full_score` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tugas harus 100% untuk dianggap selesai';
