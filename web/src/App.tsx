import { Navigate, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from './auth';
import Layout from './components/Layout';
import Login from './pages/Login';
import Home from './pages/collab/Home';
import OvertimeForm from './pages/collab/OvertimeForm';
import ConsumptionForm from './pages/collab/ConsumptionForm';
import VacationForm from './pages/collab/VacationForm';
import MyEntries from './pages/collab/MyEntries';
import Dashboard from './pages/admin/Dashboard';
import Approvals from './pages/admin/Approvals';
import ClosureNew from './pages/admin/ClosureNew';
import ClosureHistory from './pages/admin/ClosureHistory';
import ClosureDetail from './pages/admin/ClosureDetail';
import Users from './pages/admin/Users';
import AgendaShell from './pages/agenda/AgendaShell';
import Agenda from './pages/agenda/Agenda';
import Guia from './pages/agenda/Guia';
import Resumen from './pages/agenda/Resumen';

function Protected({ children, admin = false }: { children: ReactNode; admin?: boolean }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="center muted">Cargando…</div>;
  if (!user) return <Navigate to="/login" replace />;
  if (admin && user.role !== 'admin') return <Navigate to="/" replace />;
  return <Layout>{children}</Layout>;
}

export default function App() {
  const { user } = useAuth();

  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/" replace /> : <Login />} />

      <Route
        path="/"
        element={
          <Protected>
            {user?.username === 'ocaso' ? (
              <Navigate to="/agenda" replace />
            ) : user?.role === 'admin' ? (
              <Navigate to="/admin" replace />
            ) : (
              <Home />
            )}
          </Protected>
        }
      />
      <Route path="/horas-extra" element={<Protected><OvertimeForm /></Protected>} />
      <Route path="/consumo" element={<Protected><ConsumptionForm /></Protected>} />
      <Route path="/vacaciones" element={<Protected><VacationForm /></Protected>} />
      <Route path="/mis-registros" element={<Protected><MyEntries /></Protected>} />

      <Route path="/agenda" element={<Protected><AgendaShell><Agenda /></AgendaShell></Protected>} />
      <Route path="/agenda/guia" element={<Protected><AgendaShell><Guia /></AgendaShell></Protected>} />
      <Route path="/agenda/resumen" element={<Protected><AgendaShell><Resumen /></AgendaShell></Protected>} />

      <Route path="/admin" element={<Protected admin><Dashboard /></Protected>} />
      <Route path="/admin/aprobaciones" element={<Protected admin><Approvals /></Protected>} />
      <Route path="/admin/cierre" element={<Protected admin><ClosureNew /></Protected>} />
      <Route path="/admin/cierres" element={<Protected admin><ClosureHistory /></Protected>} />
      <Route path="/admin/cierres/:id" element={<Protected admin><ClosureDetail /></Protected>} />
      <Route path="/admin/usuarios" element={<Protected admin><Users /></Protected>} />

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
