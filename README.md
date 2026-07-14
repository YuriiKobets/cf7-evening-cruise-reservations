# CF7 Evening Cruise Reservations

A custom WordPress plugin for managing evening cruise reservations through Contact Form 7.

The plugin extends a standard Contact Form 7 form with booking availability, participant limits, reservation validation and a dedicated administration interface.

## Overview

This plugin was created for a company offering evening cruises.

The main goal was to replace manual reservation management with a custom WordPress solution that:

- prevents overbooking;
- controls the number of available places;
- stores every reservation separately;
- gives administrators access to reservation and customer data;
- integrates with an existing Contact Form 7 form.

## Features

- Contact Form 7 integration
- Evening cruise date selection
- Configurable booking date range
- Daily participant capacity limits
- Availability validation before form submission
- Overbooking prevention
- Separate database record for every reservation
- Customer details stored with each booking
- WordPress admin reservation list
- Reservation deletion from the administration panel
- Frontend calendar availability handling
- Support for multiple reservations on the same day
- Automatic calculation of remaining places

## Technical Highlights

- Custom WordPress plugin architecture
- Contact Form 7 hooks and validation
- Server-side reservation validation
- WordPress database integration
- Custom WordPress admin interface
- PHP-based booking and capacity logic
- JavaScript frontend calendar handling
- Data sanitization and validation

## Technologies

- WordPress
- PHP
- MySQL
- JavaScript
- jQuery
- Contact Form 7
- HTML
- CSS

## Reservation Workflow

1. The customer selects an available cruise date.
2. The customer enters the number of participants and contact details.
3. The plugin checks the remaining capacity for the selected date.
4. Contact Form 7 validates and submits the reservation.
5. The reservation is saved as a separate database record.
6. Administrators can review and manage reservations in WordPress.

## Administration

The WordPress administration panel provides access to reservation information, including:

- cruise date;
- number of participants;
- customer name;
- email address;
- telephone number;
- reservation details.

Each reservation is stored and displayed independently, including multiple reservations made for the same cruise date.

## Requirements

- WordPress
- Contact Form 7
- A configured Contact Form 7 reservation form

## Installation

1. Download or clone this repository.
2. Upload the plugin directory to:

```text
/wp-content/plugins/
```

3. Activate the plugin in the WordPress administration panel.
4. Configure the related Contact Form 7 reservation form.
5. Add the form to the required WordPress page.


## Project Purpose

This repository is published as a portfolio project demonstrating custom WordPress plugin development, Contact Form 7 integration and reservation management logic.

## Author

**Yurii Kobets**

WordPress & WooCommerce Developer

- Custom WordPress plugins
- WooCommerce development
- Contact Form 7 integrations
- REST API integrations
- ACF Pro
- PHP and JavaScript
