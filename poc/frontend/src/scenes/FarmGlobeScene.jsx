import { Canvas, useFrame } from '@react-three/fiber';
import { useRef, useMemo } from 'react';
import * as THREE from 'three';
import { OrbitControls } from '@react-three/drei';

// lat/lng → 3D position on sphere of radius `r`
const latLngToVec3 = (lat, lng, r = 2) => {
  const phi = (90 - lat) * (Math.PI / 180);
  const theta = (lng + 180) * (Math.PI / 180);
  return new THREE.Vector3(
    -(r * Math.sin(phi) * Math.cos(theta)),
    r * Math.cos(phi),
    r * Math.sin(phi) * Math.sin(theta)
  );
};

const STATUS_COLOR = {
  Occupied:  '#22c55e',
  Available: '#c9a227',
  Resting:   '#94a3b8',
};

// Build a single line object per pin via THREE directly — safer than
// using <line> as a JSX tag (which collides with SVG's <line>).
function PinConnector({ from, to, color }) {
  const lineObj = useMemo(() => {
    const geo = new THREE.BufferGeometry().setFromPoints([from, to]);
    const mat = new THREE.LineBasicMaterial({ color, transparent: true, opacity: 0.6 });
    return new THREE.Line(geo, mat);
  }, [from, to, color]);
  return <primitive object={lineObj} />;
}

function Pins({ parcelles, onSelect }) {
  return parcelles.map((p) => {
    if (p.latitude == null || p.longitude == null) return null;
    const pos = latLngToVec3(p.latitude, p.longitude, 2.04);
    const dir = pos.clone().normalize();
    const tip = pos.clone().add(dir.clone().multiplyScalar(0.25));
    const c = STATUS_COLOR[p.statut] || '#22c55e';

    return (
      <group key={p.id} onClick={(e) => { e.stopPropagation(); onSelect?.(p); }}>
        <mesh position={tip}>
          <sphereGeometry args={[0.045, 12, 12]} />
          <meshBasicMaterial color={c} />
        </mesh>
        <mesh position={tip}>
          <sphereGeometry args={[0.09, 12, 12]} />
          <meshBasicMaterial color={c} transparent opacity={0.25} />
        </mesh>
        <PinConnector from={pos} to={tip} color={c} />
      </group>
    );
  });
}

function Globe({ parcelles, onSelect }) {
  const group = useRef();
  useFrame(({ clock }) => {
    if (group.current) group.current.rotation.y = clock.elapsedTime * 0.08;
  });

  return (
    <group ref={group}>
      {/* Atmosphere */}
      <mesh>
        <sphereGeometry args={[2.05, 64, 64]} />
        <meshBasicMaterial color="#22c55e" transparent opacity={0.06} />
      </mesh>
      {/* Land */}
      <mesh>
        <sphereGeometry args={[2, 64, 64]} />
        <meshStandardMaterial color="#0a2a14" metalness={0.4} roughness={0.6} />
      </mesh>
      {/* Wireframe grid */}
      <mesh>
        <sphereGeometry args={[2.01, 32, 16]} />
        <meshBasicMaterial color="#116530" wireframe transparent opacity={0.22} />
      </mesh>
      <Pins parcelles={parcelles} onSelect={onSelect} />
    </group>
  );
}

export default function FarmGlobeScene({ parcelles = [], onSelect }) {
  return (
    <Canvas
      dpr={[1, 1.5]}
      camera={{ position: [0, 0, 6], fov: 45 }}
      gl={{ alpha: true, antialias: true, powerPreference: 'low-power' }}
      style={{ width: '100%', height: '100%' }}
    >
      <ambientLight intensity={0.4} />
      <directionalLight position={[5, 3, 5]} intensity={0.9} color="#22c55e" />
      <directionalLight position={[-5, -3, -2]} intensity={0.45} color="#c9a227" />
      <Globe parcelles={parcelles} onSelect={onSelect} />
      <OrbitControls enableZoom={false} enablePan={false} rotateSpeed={0.4} />
    </Canvas>
  );
}
