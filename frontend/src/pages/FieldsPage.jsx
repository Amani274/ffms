import { useEffect, useMemo } from 'react';
import { CircleMarker, GeoJSON, MapContainer, Popup, TileLayer, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { api } from '../api';
import { useApp } from '../auth';
import { num } from '../format';
import { Spinner, useAsync } from '../ui';
import ResourcePage from './ResourcePage';

const COLORS = { cropping: '#2a8148', pasture: '#8aa84a', orchard: '#e9a23b', fallow: '#9aa59a', infrastructure: '#7a5ca8' };

function FitBounds({ fields, farm }) {
  const map = useMap();
  useEffect(() => {
    const pts = [];
    fields.forEach((f) => { if (f.latitude && f.longitude) pts.push([Number(f.latitude), Number(f.longitude)]); });
    if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.25));
    else if (farm?.latitude) map.setView([Number(farm.latitude), Number(farm.longitude)], 13);
  }, [fields, farm, map]);
  return null;
}

export default function FieldsPage() {
  const { farm, farmId, version } = useApp();
  const { data, loading } = useAsync(() => api.get('fields', { per_page: 200 }).then((r) => r.data), [farmId, version]);

  const features = useMemo(() => (data || []).flatMap((f) => {
    if (!f.boundary_geojson) return [];
    try { return [{ field: f, geo: { type: 'Feature', properties: {}, geometry: JSON.parse(f.boundary_geojson) } }]; } catch { return []; }
  }), [data]);

  return (
    <>
      <div className="card" style={{ marginBottom: 16, overflow: 'hidden' }}>
        <div className="card-head"><h3>Farm map</h3><span className="sub">{farm?.name} · {data?.length || 0} fields · map tiles © OpenStreetMap contributors</span></div>
        {loading ? <Spinner /> : (
          <div className="map" style={{ border: 0, borderRadius: 0, marginTop: 10 }}>
            <MapContainer center={[Number(farm?.latitude ?? -15.4), Number(farm?.longitude ?? 28.3)]} zoom={13} style={{ height: '100%', width: '100%', background: '#e8efe3' }} scrollWheelZoom>
              <TileLayer attribution='&copy; OpenStreetMap contributors' url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
              <FitBounds fields={data || []} farm={farm} />
              {features.map(({ field, geo }) => (
                <GeoJSON key={`${field.id}-${field.updated_at}`} data={geo} style={{ color: COLORS[field.land_use] || '#2a8148', weight: 2, fillOpacity: 0.35 }}>
                  <Popup>
                    <b>{field.name}</b><br />{num(field.size_ha, 1)} ha · {field.soil_type || 'soil n/a'} · pH {field.soil_ph ?? '–'}<br />
                    {field.current_crop ? <>Growing: {field.current_crop}</> : <>Not currently planted</>}<br />Land use: {field.land_use}
                  </Popup>
                </GeoJSON>
              ))}
              {farm?.latitude && <CircleMarker center={[Number(farm.latitude), Number(farm.longitude)]} radius={7} pathOptions={{ color: '#12402a', fillColor: '#b9e07a', fillOpacity: 1 }}><Popup><b>{farm.name}</b><br />{farm.location}</Popup></CircleMarker>}
            </MapContainer>
          </div>
        )}
        <div style={{ display: 'flex', gap: 14, padding: '10px 18px', flexWrap: 'wrap' }}>
          {Object.entries(COLORS).map(([k, c]) => <span key={k} className="muted" style={{ fontSize: 12.5 }}><span style={{ display: 'inline-block', width: 11, height: 11, background: c, borderRadius: 3, marginRight: 6 }} />{k}</span>)}
        </div>
      </div>
      <ResourcePage name="fields" />
    </>
  );
}
