
CREATE DATABASE IF NOT EXISTS social_platform_database
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE social_platform_database;

CREATE TABLE user_types (
    user_type_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    type_code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    status TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type_id BIGINT UNSIGNED,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30),
    password_hash VARCHAR(255) NOT NULL,
    profile_image VARCHAR(500),
    gender ENUM('male','female','other'),
    birth_date DATE,
    bio TEXT,
    account_status ENUM('active','inactive','banned','deleted') DEFAULT 'active',
    email_verified TINYINT UNSIGNED DEFAULT 0 CHECK (email_verified IN (0,1)),
    activity_status TINYINT UNSIGNED DEFAULT 1 CHECK (activity_status IN (0,1)),
    is_banned TINYINT UNSIGNED DEFAULT 0 CHECK (is_banned IN (0,1)),
    ban_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    deleted_at DATETIME,
    FOREIGN KEY (user_type_id) REFERENCES user_types(user_type_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_activities (
    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    activity_type ENUM('login','logout','profile_update','product_create','product_update','product_delete','exchange','donation','complaint','other'),
    related_entity_type VARCHAR(100),
    related_entity_id BIGINT UNSIGNED,
    ip_address VARCHAR(45),
    device_info TEXT,
    browser_info TEXT,
    status ENUM('success','failed','blocked') DEFAULT 'success',
    activity_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE associations (
    association_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_name VARCHAR(200) NOT NULL,
    official_registration_number VARCHAR(100) UNIQUE,
    description TEXT,
    logo VARCHAR(500),
    email VARCHAR(150),
    phone VARCHAR(30),
    alternate_phone VARCHAR(30),
    website VARCHAR(255),
    country VARCHAR(100),
    city VARCHAR(100),
    address TEXT,
    contact_person VARCHAR(150),
    status ENUM('pending','approved','rejected','suspended') DEFAULT 'pending',
    verification_status TINYINT UNSIGNED DEFAULT 0 CHECK (verification_status IN (0,1)),
    verified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE association_members (
    membership_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(100),
    membership_type ENUM('owner','manager','representative','volunteer','member') DEFAULT 'member',
    permission_level ENUM('full','limited','view') DEFAULT 'limited',
    is_primary_contact TINYINT UNSIGNED DEFAULT 0 CHECK (is_primary_contact IN (0,1)),
    status ENUM('pending','approved','rejected','suspended') DEFAULT 'pending',
    joined_at DATETIME,
    approved_by BIGINT UNSIGNED,
    approved_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (association_id) REFERENCES associations(association_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE (association_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_category_id BIGINT UNSIGNED,
    category_name VARCHAR(150) NOT NULL,
    category_code VARCHAR(50) UNIQUE,
    description TEXT,
    image VARCHAR(500),
    display_order INT UNSIGNED DEFAULT 0,
    status TINYINT UNSIGNED DEFAULT 1 CHECK (status IN (0,1)),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE (parent_category_id, category_name)
) ENGINE=InnoDB;

CREATE TABLE products (
    product_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED,
    product_name VARCHAR(200) NOT NULL,
    description TEXT,
    condition_status ENUM('new','like_new','used','needs_repair') NOT NULL,
    listing_type ENUM('sale','exchange','donation') NOT NULL,
    status ENUM('draft','pending','published','reserved','completed','cancelled','deleted') DEFAULT 'pending',
    price DECIMAL(12,2) UNSIGNED DEFAULT 0 CHECK (price >= 0),
    available_quantity INT UNSIGNED DEFAULT 1 CHECK (available_quantity >= 1),
    location VARCHAR(255),
    city VARCHAR(100),
    exchange_allowed TINYINT UNSIGNED DEFAULT 0 CHECK (exchange_allowed IN (0,1)),
    donation_allowed TINYINT UNSIGNED DEFAULT 0 CHECK (donation_allowed IN (0,1)),
    views_count BIGINT UNSIGNED DEFAULT 0,
    requests_count BIGINT UNSIGNED DEFAULT 0,
    published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expiry_date DATETIME,
    deleted_at DATETIME,
    FOREIGN KEY (owner_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_products_listing_status_city (listing_type, status, city),
    INDEX idx_products_owner_status (owner_user_id, status),
    INDEX idx_products_category_status (category_id, status)
) ENGINE=InnoDB;

CREATE TABLE product_images (
    image_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    image_name VARCHAR(255),
    image_size BIGINT UNSIGNED,
    image_format VARCHAR(30),
    display_order INT UNSIGNED DEFAULT 0,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE exchange_requests (
    exchange_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_user_id BIGINT UNSIGNED NOT NULL,
    requested_product_id BIGINT UNSIGNED NOT NULL,
    offered_product_id BIGINT UNSIGNED NOT NULL,
    exchange_description TEXT,
    additional_notes TEXT,
    status ENUM('pending','accepted','rejected','cancelled','completed') DEFAULT 'pending',
    rejection_reason TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    response_at DATETIME,
    completed_at DATETIME,
    FOREIGN KEY (requester_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (requested_product_id) REFERENCES products(product_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (offered_product_id) REFERENCES products(product_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE uq_exchange_request_participants (requester_user_id, requested_product_id, offered_product_id),
    INDEX idx_exchange_requested_status (requested_product_id, status),
    INDEX idx_exchange_requester_status (requester_user_id, status)
) ENGINE=InnoDB;

CREATE TABLE donations (
    donation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    donor_user_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED,
    target_association_id BIGINT UNSIGNED,
    donation_type ENUM('direct','association','campaign','public') DEFAULT 'direct',
    description TEXT,
    donated_quantity INT UNSIGNED DEFAULT 1 CHECK (donated_quantity >= 1),
    status ENUM('pending','accepted','rejected','in_transit','received','completed','cancelled') DEFAULT 'pending',
    is_association_specific TINYINT UNSIGNED DEFAULT 0 CHECK (is_association_specific IN (0,1)),
    recipient_notes TEXT,
    donated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (donor_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (target_association_id) REFERENCES associations(association_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (
        (recipient_user_id IS NOT NULL AND target_association_id IS NULL)
        OR (recipient_user_id IS NULL AND target_association_id IS NOT NULL)
        OR (recipient_user_id IS NULL AND target_association_id IS NULL)
    ),
    INDEX idx_donations_status_donor (status, donor_user_id),
    INDEX idx_donations_recipient_status (recipient_user_id, status),
    INDEX idx_donations_association_status (target_association_id, status)
) ENGINE=InnoDB;

CREATE TABLE ratings (
    rating_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED,
    related_request_id BIGINT UNSIGNED,
    rating_score TINYINT UNSIGNED NOT NULL CHECK (rating_score BETWEEN 1 AND 5),
    comment TEXT,
    visibility_status ENUM('visible','hidden','deleted') DEFAULT 'visible',
    rated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewer_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (reviewed_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (related_request_id) REFERENCES exchange_requests(exchange_request_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (reviewer_user_id <> reviewed_user_id),
    UNIQUE (reviewer_user_id, reviewed_user_id, related_request_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    notification_type ENUM('system','message','exchange','donation','campaign','rating','complaint','membership','product','report') DEFAULT 'system',
    title VARCHAR(200),
    message TEXT,
    related_entity_type VARCHAR(100),
    related_entity_id BIGINT UNSIGNED,
    is_read TINYINT UNSIGNED DEFAULT 0 CHECK (is_read IN (0,1)),
    read_at DATETIME,
    is_archived TINYINT UNSIGNED DEFAULT 0 CHECK (is_archived IN (0,1)),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATETIME,
    FOREIGN KEY (recipient_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_notifications_recipient_read_created (recipient_user_id, is_read, created_at),
    INDEX idx_notifications_recipient_archived (recipient_user_id, is_archived)
) ENGINE=InnoDB;

CREATE TABLE conversations (
    conversation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_type ENUM('direct','support','exchange','donation','complaint') DEFAULT 'direct',
    related_product_id BIGINT UNSIGNED,
    related_request_id BIGINT UNSIGNED,
    creator_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200),
    status ENUM('active','closed','archived','blocked') DEFAULT 'active',
    last_message_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (related_product_id) REFERENCES products(product_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (related_request_id) REFERENCES exchange_requests(exchange_request_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (creator_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_conversations_creator_status (creator_user_id, status)
) ENGINE=InnoDB;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    message_type ENUM('text','image','file','system') DEFAULT 'text',
    content TEXT,
    attachment_path VARCHAR(500),
    reply_to_message_id BIGINT UNSIGNED,
    is_edited TINYINT UNSIGNED DEFAULT 0 CHECK (is_edited IN (0,1)),
    is_deleted TINYINT UNSIGNED DEFAULT 0 CHECK (is_deleted IN (0,1)),
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (sender_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (reply_to_message_id) REFERENCES messages(message_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_messages_conversation_sent (conversation_id, sent_at),
    INDEX idx_messages_sender_sent (sender_user_id, sent_at)
) ENGINE=InnoDB;

CREATE TABLE roles (
    role_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    role_code VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    is_system_role TINYINT UNSIGNED DEFAULT 0 CHECK (is_system_role IN (0,1)),
    status TINYINT UNSIGNED DEFAULT 1 CHECK (status IN (0,1)),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(150) NOT NULL,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    permission_section VARCHAR(100),
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status TINYINT UNSIGNED DEFAULT 1 CHECK (status IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED,
    granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE (role_id, permission_id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_role_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    association_id BIGINT UNSIGNED,
    assigned_by BIGINT UNSIGNED,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATETIME,
    status ENUM('active','expired','revoked') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (association_id) REFERENCES associations(association_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE (user_id, role_id, association_id)
) ENGINE=InnoDB;

CREATE TABLE admin_operations_log (
    admin_operation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    operation_type VARCHAR(100),
    affected_entity_type VARCHAR(100),
    affected_entity_id BIGINT UNSIGNED,
    old_data JSON,
    new_data JSON,
    operation_description TEXT,
    ip_address VARCHAR(45),
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE favorite_products (
    favorite_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE (user_id, product_id)
) ENGINE=InnoDB;

CREATE TABLE search_history (
    search_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    search_query VARCHAR(500),
    category_id BIGINT UNSIGNED,
    desired_listing_type ENUM('sale','exchange','donation'),
    location VARCHAR(255),
    min_price DECIMAL(12,2) UNSIGNED,
    max_price DECIMAL(12,2) UNSIGNED,
    desired_condition ENUM('new','like_new','used','needs_repair'),
    results_count INT UNSIGNED DEFAULT 0,
    searched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (min_price IS NULL OR max_price IS NULL OR min_price <= max_price),
    INDEX idx_search_history_user_date (user_id, searched_at),
    INDEX idx_search_history_category_listing (category_id, desired_listing_type)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(150) NOT NULL UNIQUE,
    setting_value TEXT,
    value_type ENUM('string','integer','boolean','json','decimal') NOT NULL DEFAULT 'string',
    description TEXT,
    setting_category ENUM('general','localization','security','products','associations','uploads','interface','donations','community','communication','system') DEFAULT 'general',
    is_editable TINYINT UNSIGNED DEFAULT 1 CHECK (is_editable IN (0,1)),
    updated_by BIGINT UNSIGNED,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE verification_codes (
    verification_code_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    verification_type ENUM('email','phone','password_reset','login','account_activation') NOT NULL,
    target_contact VARCHAR(150),
    code_hash VARCHAR(255) NOT NULL,
    is_used TINYINT UNSIGNED DEFAULT 0 CHECK (is_used IN (0,1)),
    attempts_count TINYINT UNSIGNED DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_verification_user_type_used (user_id, verification_type, is_used),
    INDEX idx_verification_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_requests (
    login_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    login_identifier VARCHAR(150),
    request_type ENUM('password','otp','magic_link','social') DEFAULT 'password',
    ip_address VARCHAR(45),
    device_info TEXT,
    status ENUM('pending','verified','failed','expired','cancelled') DEFAULT 'pending',
    requires_verification TINYINT UNSIGNED DEFAULT 0 CHECK (requires_verification IN (0,1)),
    verification_code_id BIGINT UNSIGNED,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (verification_code_id) REFERENCES verification_codes(verification_code_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_login_requests_identifier_date (login_identifier, submitted_at),
    INDEX idx_login_requests_status_date (status, submitted_at)
) ENGINE=InnoDB;

CREATE TABLE donation_campaigns (
    campaign_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizing_association_id BIGINT UNSIGNED NOT NULL,
    creator_user_id BIGINT UNSIGNED NOT NULL,
    campaign_name VARCHAR(200) NOT NULL,
    description TEXT,
    campaign_image VARCHAR(500),
    campaign_type ENUM('goods','money','mixed','services') DEFAULT 'goods',
    start_date DATETIME,
    end_date DATETIME,
    target_quantity INT UNSIGNED DEFAULT 0,
    target_amount DECIMAL(14,2) UNSIGNED DEFAULT 0,
    achieved_quantity INT UNSIGNED DEFAULT 0,
    achieved_amount DECIMAL(14,2) UNSIGNED DEFAULT 0,
    status ENUM('draft','pending','active','paused','completed','cancelled') DEFAULT 'pending',
    campaign_location VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organizing_association_id) REFERENCES associations(association_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (creator_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT ck_campaign_dates CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date),
    CONSTRAINT ck_campaign_quantity CHECK (achieved_quantity <= target_quantity OR target_quantity = 0),
    CONSTRAINT ck_campaign_amount CHECK (achieved_amount <= target_amount OR target_amount = 0),
    INDEX idx_campaign_status_dates (status, start_date, end_date),
    INDEX idx_campaign_association_status (organizing_association_id, status)
) ENGINE=InnoDB;

CREATE TABLE campaign_items (
    campaign_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED,
    item_name VARCHAR(200) NOT NULL,
    description TEXT,
    required_quantity INT UNSIGNED DEFAULT 1 CHECK (required_quantity >= 1),
    received_quantity INT UNSIGNED DEFAULT 0,
    accepted_condition ENUM('new','like_new','used','needs_repair','any') DEFAULT 'any',
    priority ENUM('low','medium','high','critical') DEFAULT 'medium',
    status ENUM('active','completed','cancelled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES donation_campaigns(campaign_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_campaign_item_quantity CHECK (received_quantity <= required_quantity),
    UNIQUE uq_campaign_item_name (campaign_id, item_name),
    INDEX idx_campaign_items_campaign_status (campaign_id, status)
) ENGINE=InnoDB;

CREATE TABLE donation_receipts (
    receipt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id BIGINT UNSIGNED NOT NULL,
    receiving_association_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED,
    campaign_item_id BIGINT UNSIGNED,
    receiving_user_id BIGINT UNSIGNED,
    beneficiary_name VARCHAR(150),
    beneficiary_user_id BIGINT UNSIGNED,
    received_quantity INT UNSIGNED DEFAULT 0,
    delivered_quantity INT UNSIGNED DEFAULT 0,
    receipt_number VARCHAR(100) UNIQUE,
    receipt_image VARCHAR(500),
    status ENUM('pending','received','partially_delivered','delivered','cancelled') DEFAULT 'pending',
    received_at DATETIME,
    delivered_at DATETIME,
    notes TEXT,
    FOREIGN KEY (donation_id) REFERENCES donations(donation_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (receiving_association_id) REFERENCES associations(association_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES donation_campaigns(campaign_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (campaign_item_id) REFERENCES campaign_items(campaign_item_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (receiving_user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (beneficiary_user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_receipt_delivered_quantity CHECK (delivered_quantity <= received_quantity),
    INDEX idx_receipts_status_received (status, received_at),
    INDEX idx_receipts_association_status (receiving_association_id, status)
) ENGINE=InnoDB;

CREATE TABLE campaign_participants (
    participation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    association_id BIGINT UNSIGNED,
    participation_type ENUM('volunteer','donor','coordinator','beneficiary') DEFAULT 'volunteer',
    participant_role VARCHAR(100),
    contribution_type ENUM('items','money','time','services') DEFAULT 'items',
    contribution_description TEXT,
    contribution_quantity INT UNSIGNED DEFAULT 0,
    status ENUM('pending','approved','rejected','completed','cancelled') DEFAULT 'pending',
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME,
    completed_at DATETIME,
    FOREIGN KEY (campaign_id) REFERENCES donation_campaigns(campaign_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (association_id) REFERENCES associations(association_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE (campaign_id, user_id),
    INDEX idx_campaign_participants_status (campaign_id, status)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    complaint_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complainant_user_id BIGINT UNSIGNED NOT NULL,
    accused_user_id BIGINT UNSIGNED,
    product_id BIGINT UNSIGNED,
    association_id BIGINT UNSIGNED,
    complaint_title VARCHAR(200) NOT NULL,
    complaint_details TEXT,
    priority ENUM('low','medium','high','critical') DEFAULT 'medium',
    status ENUM('pending','under_review','assigned','resolved','rejected','closed') DEFAULT 'pending',
    assigned_admin_id BIGINT UNSIGNED,
    admin_response TEXT,
    resolution TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME,
    FOREIGN KEY (complainant_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (accused_user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (association_id) REFERENCES associations(association_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (assigned_admin_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_complaints_status_priority (status, priority),
    INDEX idx_complaints_assigned_status (assigned_admin_id, status)
) ENGINE=InnoDB;

CREATE TABLE user_sessions (
    session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    session_token_hash VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(150),
    device_type ENUM('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
    operating_system VARCHAR(100),
    browser_info TEXT,
    ip_address VARCHAR(45),
    approximate_location VARCHAR(255),
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME,
    expires_at DATETIME,
    revoked_at DATETIME,
    status ENUM('active','expired','revoked','locked') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user_sessions_user_status (user_id, status),
    INDEX idx_user_sessions_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_logout_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    login_identifier VARCHAR(150),
    attempt_type ENUM('login','logout') NOT NULL,
    attempt_result ENUM('success','failed','blocked') NOT NULL,
    failure_reason TEXT,
    ip_address VARCHAR(45),
    device_info TEXT,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_login_attempts_user_date (user_id, attempted_at),
    INDEX idx_login_attempts_identifier_date (login_identifier, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE reports (
    report_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(200) NOT NULL,
    report_type ENUM('users','products','transactions','donations','associations','complaints','audits','general') DEFAULT 'general',
    description TEXT,
    creator_user_id BIGINT UNSIGNED NOT NULL,
    association_id BIGINT UNSIGNED,
    period_start DATE,
    period_end DATE,
    filter_criteria JSON,
    report_data JSON,
    report_file_path VARCHAR(500),
    file_format ENUM('pdf','xlsx','csv','json','html'),
    status ENUM('pending','generating','completed','failed','cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (association_id) REFERENCES associations(association_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_report_period CHECK (period_start IS NULL OR period_end IS NULL OR period_start <= period_end),
    INDEX idx_reports_creator_status (creator_user_id, status),
    INDEX idx_reports_association_status (association_id, status)
) ENGINE=InnoDB;

CREATE TABLE offers (
    offer_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    offer_user_id BIGINT UNSIGNED NOT NULL,
    product_owner_id BIGINT UNSIGNED NOT NULL,
    offer_type ENUM('price','exchange','mixed') NOT NULL,
    proposed_price DECIMAL(12,2) UNSIGNED,
    currency VARCHAR(10),
    alternative_product_id BIGINT UNSIGNED,
    description TEXT,
    status ENUM('pending','accepted','rejected','cancelled','expired') DEFAULT 'pending',
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    responded_at DATETIME,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (offer_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_owner_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (alternative_product_id) REFERENCES products(product_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_offer_price CHECK (proposed_price IS NULL OR proposed_price >= 0),
    UNIQUE uq_offer_per_product (product_id, offer_user_id, offer_type),
    INDEX idx_offers_owner_status (product_owner_id, status),
    INDEX idx_offers_expiry_status (expires_at, status)
) ENGINE=InnoDB;

CREATE TABLE product_reports (
    product_report_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    reporter_user_id BIGINT UNSIGNED NOT NULL,
    violation_type ENUM('fraud','prohibited_item','misleading','counterfeit','spam','harassment','other') DEFAULT 'other',
    details TEXT,
    evidence_path VARCHAR(500),
    status ENUM('pending','under_review','action_taken','dismissed','closed') DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED,
    admin_notes TEXT,
    action_taken TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME,
    closed_at DATETIME,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (reporter_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE uq_product_reporter_product (product_id, reporter_user_id),
    INDEX idx_product_reports_status_submitted (status, submitted_at),
    INDEX idx_product_reports_reviewer_status (reviewed_by, status)
) ENGINE=InnoDB;

CREATE TABLE user_requests (
    request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED,
    request_title VARCHAR(200) NOT NULL,
    request_details TEXT,
    request_type ENUM('purchase','exchange','donation','service','other') DEFAULT 'other',
    required_quantity INT UNSIGNED DEFAULT 1,
    min_price DECIMAL(12,2) UNSIGNED,
    max_price DECIMAL(12,2) UNSIGNED,
    location VARCHAR(255),
    city VARCHAR(100),
    status ENUM('draft','active','pending','fulfilled','expired','cancelled') DEFAULT 'active',
    expiry_date DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_user_request_price_range CHECK (min_price IS NULL OR max_price IS NULL OR min_price <= max_price),
    CONSTRAINT ck_user_request_quantity CHECK (required_quantity >= 1),
    INDEX idx_user_requests_user_status (user_id, status),
    INDEX idx_user_requests_category_status (category_id, status)
) ENGINE=InnoDB;
