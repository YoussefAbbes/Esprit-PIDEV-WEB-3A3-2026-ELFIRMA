import { Canvas, useFrame } from '@react-three/fiber';
import { useRef, useMemo } from 'react';
import * as THREE from 'three';

// Sensor network — dim wireframe icosahedrons connected by edges.
function SensorMesh({ count = 90 }) {
  const group = useRef();
  const accentRefs = useRef([]);

  const { nodes, lines } = useMemo(() => {
    const nodes = [];
    for (let i = 0; i < count; i++) {
      nodes.push(new THREE.Vector3(
        (Math.random() - 0.5) * 90,
        (Math.random() - 0.5) * 65,
        (Math.random() - 0.5) * 35
      ));
    }
    const lines = [];
    for (let i = 0; i < count; i++) {
      for (let j = i + 1; j < count; j++) {
        if (nodes[i].distanceTo(nodes[j]) < 14) {
          lines.push([nodes[i], nodes[j]]);
        }
      }
    }
    return { nodes, lines };
  }, [count]);

  const lineGeo = useMemo(() => {
    const positions = new Float32Array(lines.length * 6);
    lines.forEach(([a, b], i) => {
      positions[i * 6]     = a.x;
      positions[i * 6 + 1] = a.y;
      positions[i * 6 + 2] = a.z;
      positions[i * 6 + 3] = b.x;
      positions[i * 6 + 4] = b.y;
      positions[i * 6 + 5] = b.z;
    });
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    return g;
  }, [lines]);

  // 14 random "active" sensors that pulse
  const accents = useMemo(
    () => Array.from({ length: 14 }, () => nodes[Math.floor(Math.random() * nodes.length)]),
    [nodes]
  );

  useFrame((state) => {
    const t = state.clock.elapsedTime;
    if (group.current) {
      group.current.rotation.y = t * 0.04;
      group.current.rotation.x = Math.sin(t * 0.03) * 0.05;
    }
    accentRefs.current.forEach((m, i) => {
      if (!m) return;
      const s = 1 + Math.sin(t * 2 + i * 0.5) * 0.4;
      m.scale.setScalar(s);
    });
  });

  return (
    <group ref={group}>
      {nodes.map((n, i) => (
        <mesh key={i} position={n}>
          <icosahedronGeometry args={[0.22, 0]} />
          <meshBasicMaterial color="#116530" wireframe transparent opacity={0.65} />
        </mesh>
      ))}
      <lineSegments geometry={lineGeo}>
        <lineBasicMaterial color="#0d3d1a" transparent opacity={0.32} />
      </lineSegments>
      {accents.map((p, i) => (
        <group key={i} position={p} ref={(el) => (accentRefs.current[i] = el)}>
          <mesh>
            <sphereGeometry args={[0.14, 12, 12]} />
            <meshBasicMaterial color="#22c55e" />
          </mesh>
          {/* Soft halo without postprocessing */}
          <mesh>
            <sphereGeometry args={[0.32, 12, 12]} />
            <meshBasicMaterial color="#22c55e" transparent opacity={0.18} />
          </mesh>
        </group>
      ))}
    </group>
  );
}

export default function AdminBackgroundScene() {
  return (
    <Canvas
      className="canvas-fixed"
      dpr={1}
      camera={{ position: [0, 0, 38], fov: 60 }}
      gl={{ alpha: true, antialias: true }}
      style={{ opacity: 0.6 }}
    >
      <SensorMesh />
    </Canvas>
  );
}
