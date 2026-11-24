import AdminApp from "@CoreBundle/components/admin/AdminApp";
import AppRoot from "@CoreBundle/components/layout/AppRoot";
import { createRoot } from "react-dom/client";
import React from "react";

// Admin pages (CoreBundle AdminController), served on every subdomain to the admins only
createRoot(document.getElementById('root')).render(<AppRoot><AdminApp/></AppRoot>);
