
# Smart Campus Lost and Found Portal

A full-stack web application developed for the
ICT3131 Web Technology Mini Project at
Manipal Institute of Technology, Manipal.

## Project Overview

The Smart Campus Lost and Found Portal helps
students report, search, and recover lost
belongings within the campus.

The system includes role-based access,
smart matching suggestions, ownership
verification, and notifications.

## Features

- Student registration and login
- Report lost and found items
- Upload item photographs
- Browse and search reports
- Rule-based matching suggestions
- Submit ownership claims
- Track claim status
- Admin claim approval and rejection
- Item handover confirmation
- Notifications
- Admin report management
- Role-based access control

## Technologies Used

- HTML5
- CSS3
- JavaScript
- PHP
- MySQL / MariaDB
- XAMPP
- phpMyAdmin

## Installation

1. Install XAMPP.
2. Copy the project folder into `xampp/htdocs`.
3. Start Apache and MySQL.
4. Open phpMyAdmin.
5. Import `schema.sql` to create the database.
6. Copy `db.example.php` to `db.php`.
7. Update the database credentials in `db.php`.
8. Open the project in your browser.

Example URL:

http://localhost/Campus_lost_found/

If Apache runs on port 8080:

http://localhost:8080/Campus_lost_found/

## User Roles

### Student

Students can report items, browse reports,
view matching suggestions, submit claims,
and receive notifications.

### Administrator

Administrators can review claims,
approve or reject ownership requests,
confirm item handovers, and manage reports.

Note: New accounts are registered as students.
Administrator accounts must be provisioned
separately through an authorized process.

## Security Features

- Password hashing
- Prepared SQL statements
- Session-based authentication
- CSRF protection
- Role-based authorization
- Input validation
- Restricted image uploads

## Project Team

Pushpa Manjunath Moger

Debdyuti Sarkar

## Faculty Guide

Ms Swathi B.P

## Institution

Manipal Institute of Technology, Manipal

BTech Information Technology

October 2026
