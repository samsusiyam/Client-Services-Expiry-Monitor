# Client Services & Expiry Monitor (WHMCS Addon Module)

**Client Services & Expiry Monitor** হলো একটি কাস্টম WHMCS অ্যাডন মডিউল যা অ্যাডমিনদের ক্লায়েন্টের সমস্ত একটিভ সার্ভিস ও ডোমেইনের রিনিউয়াল/মেয়াদ শেষের সময় অনুযায়ী লাইভ ট্র্যাক করতে এবং ওয়ান-ক্লিক WhatsApp ও কলের মাধ্যমে যোগাযোগ করতে সাহায্য করে।

---

## 🚀 প্রধান বৈশিষ্ট্যসমূহ (Key Features)

1. **Next Due Date Ascending Sorting (মেয়াদ অনুযায়ী অটো সর্টিং)**:
   - ডিফল্টভাবে শুধু **Active** প্রোডাক্ট/সার্ভিসগুলো প্রদর্শিত হবে।
   - যে প্রোডাক্টের মেয়াদ সবচেয়ে আগে শেষ হবে (Due Soonest), সেটি সবার উপরে থাকবে।
2. **ক্লায়েন্ট ফোন নম্বর ও ওয়ান-ক্লিক যোগাযোগ**:
   - ইউজারের ফোন নম্বর স্পষ্ট ডিসপ্লে।
   - **WhatsApp Chat Modal & Button**: সরাসরি ক্লিক করে প্রি-ডিফাইনড টেমপ্লেট সহ (যেমন: রিনিউয়াল রিমাইন্ডার, ওভারডিউ নোটিশ) হোয়াটসঅ্যাপ চ্যাট শুরু করা যায়।
   - **Direct Call (`tel:`) বাটন** ও **1-Click Copy Number বাটন**।
3. **মাল্টি-লেভেল ফিল্টারিং (Filtering Options)**:
   - **Product Type**: VPS/Dedicated Server, Shared Hosting, Reseller Hosting, Other Services, এবং Domains।
   - **Specific Package/Product**: নির্দিষ্ট প্রোডাক্ট অনুযায়ী ফিল্টার।
   - **Next Due Quick Filters**: Due Today, Due in 3 Days, Due in 7 Days, Due in 15 Days, Due in 30 Days, Overdue।
   - **Server / Payment Method / Status** ফিল্টারিং।
   - **Live Instant Search**: পেজ রিলোড ছাড়া ক্লায়েন্ট নেম, ফোন নম্বর, ডোমেইন, আইপি, ইউজারনেম দিয়ে ইনস্ট্যান্ট লাইভ সার্চ।
4. **মেট্রিক কার্ডস (Summary Metric Cards)**:
   - Active Services, Due Today, Due in 3 Days, Due in 7 Days, Overdue কাউন্ট।
5. **অটো রিফ্রেশ (Live Auto-Refresh)**:
   - প্রতি ৩০ সেকেন্ড, ৬০ সেকেন্ড বা ২ মিনিট পর পর টেবিল স্বয়ংক্রিয়ভাবে রিফ্রেশ হওয়ার সুবিধা।

---

## 📂 ইনস্টলেশন নির্দেশিকা (Installation Guide)

1. আপনার WHMCS রুট ডিরেক্টরির `modules/addons/` ফোল্ডারে এই মডিউল ফোল্ডারটি কপি করুন:
   ```text
   /whmcs_root/modules/addons/client_services_monitor/
   ```
2. আপনার **WHMCS Admin Area** তে লগইন করুন।
3. **Setup** (বা Configuration) ➔ **Addon Modules** এ যান।
4. **Client Services & Expiry Monitor** মডিউলটি খুঁজে পেয়ে **Activate** বাটনে ক্লিক করুন।
5. **Configure** বাটনে ক্লিক করে:
   - আপনার রোল অনুযায়ী Access Control (যেমন: Full Administrator) টিক দিন।
   - Default Status, Expiring Soon Warning Days, Default WhatsApp Country Code (যেমন: `880`) সেট করুন।
6. **Save Changes** এ ক্লিক করুন।
7. এখন **Addons** ➔ **Client Services & Expiry Monitor** মেনু থেকে মডিউলটি ব্যবহার শুরু করুন।

---

## 🛠️ ফাইল কাঠামো (File Structure)

```text
modules/addons/client_services_monitor/
├── client_services_monitor.php      # Main Addon Entrypoint & Backend AJAX Controller
├── hooks.php                        # WHMCS Admin Hooks
├── README.md                        # Documentation
├── templates/
│   └── admin_dashboard.php          # Admin UI Template
└── assets/
    ├── css/
    │   └── style.css                # Custom Stylesheet & Badges
    └── js/
        └── app.js                   # AJAX Logic, Debounced Live Search, WhatsApp Modal
```
