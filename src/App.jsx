import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './AuthContext.jsx'
import Layout from './components/Layout.jsx'
import { Loading } from './components/UI.jsx'
import Dashboard from './pages/Dashboard.jsx'
import Expiration from './pages/Expiration.jsx'
import ForgotPassword from './pages/ForgotPassword.jsx'
import Inventory from './pages/Inventory.jsx'
import Login from './pages/Login.jsx'
import MedicineForm from './pages/MedicineForm.jsx'
import MedicineView from './pages/MedicineView.jsx'
import Notifications from './pages/Notifications.jsx'
import Reports from './pages/Reports.jsx'
import ResetPassword from './pages/ResetPassword.jsx'
import Settings from './pages/Settings.jsx'
import StockIn from './pages/StockIn.jsx'
import StockOut from './pages/StockOut.jsx'

function Protected({ children }) {
  const { authenticated, loading } = useAuth()
  if (loading) return <Loading label="Checking your session…" />
  if (!authenticated) return <Navigate to="/login" replace />
  return children
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route element={<Protected><Layout /></Protected>}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/inventory" element={<Inventory />} />
        <Route path="/inventory/new" element={<MedicineForm />} />
        <Route path="/inventory/:id" element={<MedicineView />} />
        <Route path="/inventory/:id/edit" element={<MedicineForm />} />
        <Route path="/stock-in" element={<StockIn />} />
        <Route path="/stock-out" element={<StockOut />} />
        <Route path="/expiration" element={<Expiration />} />
        <Route path="/reports" element={<Reports />} />
        <Route path="/notifications" element={<Notifications />} />
        <Route path="/settings" element={<Settings />} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
