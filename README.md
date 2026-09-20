# Client Services & Expiry Monitor (WHMCS Addon Module)

**Client Services & Expiry Monitor** is a powerful, real-time WHMCS Addon Module developed by **Bahari IT** that enables WHMCS administrators to monitor all active client products, services, and domains sorted by next renewal / expiry date (Next Due Date), with instant live filtering, clean formatted phone numbers, and 1-click WhatsApp & Call integration.

---

## 🚀 Key Features

1. **Real-time Expiry Sorting (Next Due Date ASC)**:
   - Displays only **Active** services by default.
   - Automatically sorted with services expiring soonest at the very top.
   - Color-coded badges for *Due Today*, *Due in X days*, *Overdue by X days*, and *Active*.
2. **Clean Client Phone & Quick Connect**:
   - Strips WHMCS default country dots (e.g. `+880 1317-878503`).
   - **1-Click WhatsApp Chat**: Opens WhatsApp with pre-filled customizable reminder and invoice notices.
   - **Direct Call (`tel:`)**: One-click dialer trigger.
   - **1-Click Copy**: Instant clipboard copy with visual checkmark feedback.
3. **Multi-Type & Advanced Filters**:
   - **Product Types**: VPS / Dedicated Servers, Shared Hosting, Reseller Hosting, Other Services, and Domain Registrations.
   - **Next Due Quick Filters**: Due Today, Due in 3 Days, Due in 7 Days, Due in 15 Days, Due in 30 Days / This Month, Overdue.
   - **Server / Payment Method / Status Filters**.
   - **Live Instant Search**: Debounced instant search by Client Name, Phone, Email, Domain, IP, Username, or Service ID without any page reload.
4. **Summary Metric Cards**:
   - Interactive metric cards for Active Services, Due Today, Due in 3 Days, Due in 7 Days, and Overdue.
5. **Real-time Auto-Refresh Polling**:
   - Live background auto-refresh toggle (15s, 30s, 60s, 2m, or Manual) for real-time monitoring.

---

## 📂 Installation Guide

1. Upload or copy the `client_services_monitor` directory into your WHMCS installation:
   ```text
   /your_whmcs_root/modules/addons/client_services_monitor/
   ```
2. Log in to your **WHMCS Admin Area**.
3. Navigate to **System Settings** (or Setup ⚙️) ➔ **Addon Modules** (URL: `/admin/configaddonmods.php`).
4. Find **Client Services & Expiry Monitor** and click **Activate**.
5. Click **Configure**:
   - Under **Access Control**, check **Full Administrator** (or your administrator role).
   - Configure default options (Default Status, Expiring Soon Warning Days, Default WhatsApp Country Code).
6. Click **Save Changes**.
7. Access the module from **Addons** ➔ **Client Services & Expiry Monitor** or via direct URL:
   ```text
   https://your-domain.com/admin/addonmodules.php?module=client_services_monitor
   ```

---

## 📁 File Structure

```text
modules/addons/client_services_monitor/
├── client_services_monitor.php      # Main Addon Entrypoint & AJAX Controller
├── hooks.php                        # WHMCS Admin Hooks
├── whmcs.json                       # WHMCS 8+ Apps & Integrations Manifest
├── logo.png                         # Module Icon
├── README.md                        # Documentation
├── index.php                        # Security Direct Access Prevention
├── templates/
│   └── admin_dashboard.php          # Responsive Admin UI Template
└── assets/
    ├── css/
    │   └── style.css                # Modern Pro UI Stylesheet & Badges
    └── js/
        └── app.js                   # Live Search, Debouncing & Realtime Controller
```

---

## 👨‍💻 Author & Support

- **Author**: Bahari IT
- **Repository**: [https://github.com/samsusiyam/Client-Services-Expiry-Monitor](https://github.com/samsusiyam/Client-Services-Expiry-Monitor)
- **License**: Proprietary / GNU GPLv3
