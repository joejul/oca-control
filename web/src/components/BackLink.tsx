import {Link} from 'react-router-dom';

export default function BackLink({to = '/', label = 'Volver'}: { to?: string; label?: string }) {
    return (
        <Link to={to} className="back-link">⬅ {label}</Link>
    );
}
