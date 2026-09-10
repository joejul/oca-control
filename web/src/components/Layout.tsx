import type {ReactNode} from 'react';
import {NavLink, useNavigate} from 'react-router-dom';
import {useAuth} from '../auth';

export default function Layout({children}: { children: ReactNode }) {
    const {user, logout} = useAuth();
    const navigate = useNavigate();

    async function handleLogout() {
        await logout();
        navigate('/login', {replace: true});
    }

    const isAdmin = user?.role === 'admin';

    return (
        <div className="app">
            <header className="topbar">
                <div className="brand">OCASO CAFE BAR</div>
                <div className="topbar-right">
                    {user && (<span className="welcome">Bienvenido <strong>{user.display_name}</strong></span>)}

                    <button type="button" className="btn ghost sm" onClick={handleLogout}> Salir</button>
                </div>
            </header>

            {isAdmin && (
                <nav className="tabs">
                    <NavLink to="/admin" end>
                        Panel
                    </NavLink>
                    <NavLink to="/admin/aprobaciones">Aprobaciones</NavLink>
                    <NavLink to="/admin/cierre">Cierre</NavLink>
                    <NavLink to="/admin/cierres">Historial</NavLink>
                    <NavLink to="/admin/usuarios">Usuarios</NavLink>
                </nav>
            )}

            <main className="content">{children}</main>
        </div>
    );
}
