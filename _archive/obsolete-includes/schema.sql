-- Raw-Wire Dashboard Database Schema
-- Phase 1: Core Infrastructure
-- Reference: DASHBOARD_SPEC.md

-- Content items table for Federal Register findings
CREATE TABLE IF NOT EXISTS wp_rawwire_content (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title TEXT,
    content LONGTEXT,
    source_url VARCHAR(500),
    document_number VARCHAR(100),
    publication_date DATE,
    agency VARCHAR(200),
    category VARCHAR(100),
    relevance_score DECIMAL(5,2),
    approval_status ENUM('pending', 'approved', 'rejected', 'published') DEFAULT 'pending',
    approved_by BIGINT,
    approved_at DATETIME,
    created_at DATETIME,
    updated_at DATETIME,
    metadata JSON,
    INDEX idx_status (approval_status),
    INDEX idx_date (publication_date),
    INDEX idx_relevance (relevance_score),
    INDEX idx_document_number (document_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automation logs table for tracking system activity
CREATE TABLE IF NOT EXISTS wp_rawwire_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    log_type ENUM('fetch', 'process', 'error', 'api_call', 'activity') DEFAULT 'activity',
    message TEXT,
    details JSON,
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    created_at DATETIME,
    INDEX idx_type (log_type),
    INDEX idx_severity (severity),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
