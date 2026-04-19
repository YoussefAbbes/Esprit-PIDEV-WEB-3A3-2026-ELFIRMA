#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#Raspberry Pi irrigation control script
import lgpio
import time
from datetime import datetime
import mysql.connector

# GPIO
RELAY_PIN = 17
SOIL_PIN = 27
PUMP_ON_LEVEL = 1
PUMP_OFF_LEVEL = 0
SENSOR_DRY_VALUE = 0  # your case: dry => 0

# DB
DB_CFG = {
    "host": "172.20.10.5",   # <-- your PC IP
    "user": "pi_writer",
    "password": "abbes2023ab",
    "database": "personne",
    "port": 3306
}

PARCELLE_ID = 1  # <-- change

def now_dt():
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")

def log_event(conn, source, event_type, message, soil_value=None, needs_water=None):
    q = """INSERT INTO irrigation_event
           (parcelle_id, source, event_type, message, soil_value, needs_water, created_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s)"""
    cur = conn.cursor()
    cur.execute(q, (PARCELLE_ID, source, event_type, message, soil_value, needs_water, now_dt()))
    conn.commit()
    cur.close()

def get_latest_mode(conn):
    q = """SELECT command FROM irrigation_command
           WHERE parcelle_id=%s AND status='PENDING'
           ORDER BY id ASC LIMIT 1"""
    cur = conn.cursor()
    cur.execute(q, (PARCELLE_ID,))
    row = cur.fetchone()
    cur.close()
    return row[0] if row else None

def ack_latest_mode(conn, command):
    q = """UPDATE irrigation_command
           SET status='ACK', processed_at=%s
           WHERE parcelle_id=%s AND status='PENDING' AND command=%s
           ORDER BY id ASC LIMIT 1"""
    cur = conn.cursor()
    cur.execute(q, (now_dt(), PARCELLE_ID, command))
    conn.commit()
    cur.close()

def upsert_state(conn, mode, pump_running, soil_value, needs_water):
    q = """
    INSERT INTO irrigation_state (parcelle_id, mode, pump_running, soil_value, needs_water, updated_at)
    VALUES (%s,%s,%s,%s,%s,%s)
    ON DUPLICATE KEY UPDATE
      mode=VALUES(mode),
      pump_running=VALUES(pump_running),
      soil_value=VALUES(soil_value),
      needs_water=VALUES(needs_water),
      updated_at=VALUES(updated_at)
    """
    cur = conn.cursor()
    cur.execute(q, (PARCELLE_ID, mode, int(pump_running), soil_value, int(needs_water), now_dt()))
    conn.commit()
    cur.close()

chip = lgpio.gpiochip_open(0)
for pin in [RELAY_PIN, SOIL_PIN]:
    try: lgpio.gpio_free(chip, pin)
    except: pass

mode = "AUTO"
pump_running = False

try:
    lgpio.gpio_claim_output(chip, RELAY_PIN, PUMP_OFF_LEVEL)
    lgpio.gpio_claim_input(chip, SOIL_PIN, lgpio.SET_PULL_NONE)

    while True:
        conn = mysql.connector.connect(**DB_CFG)

        # 1) Check web command
        cmd = get_latest_mode(conn)
        if cmd in ("AUTO", "MANUAL_ON", "MANUAL_OFF"):
            mode = cmd
            ack_latest_mode(conn, cmd)
            log_event(conn, "WEB", "MODE_CHANGED", f"Mode changed to {mode}")

        # 2) Read sensor
        soil_value = lgpio.gpio_read(chip, SOIL_PIN)
        needs_water = (soil_value == SENSOR_DRY_VALUE)

        # 3) Apply mode
        if mode == "MANUAL_ON":
            if not pump_running:
                lgpio.gpio_write(chip, RELAY_PIN, PUMP_ON_LEVEL)
                pump_running = True
                log_event(conn, "DEVICE", "PUMP_ON_MANUAL", "Manual ON applied", soil_value, needs_water)

        elif mode == "MANUAL_OFF":
            if pump_running:
                lgpio.gpio_write(chip, RELAY_PIN, PUMP_OFF_LEVEL)
                pump_running = False
                log_event(conn, "DEVICE", "PUMP_OFF_MANUAL", "Manual OFF applied", soil_value, needs_water)

        else:  # AUTO
            if needs_water and not pump_running:
                lgpio.gpio_write(chip, RELAY_PIN, PUMP_ON_LEVEL)
                pump_running = True
                log_event(conn, "AUTO", "PUMP_ON_AUTO", "Soil dry -> Pump ON", soil_value, needs_water)
            elif (not needs_water) and pump_running:
                lgpio.gpio_write(chip, RELAY_PIN, PUMP_OFF_LEVEL)
                pump_running = False
                log_event(conn, "AUTO", "PUMP_OFF_AUTO", "Soil wet -> Pump OFF", soil_value, needs_water)

        # 4) Save state
        upsert_state(conn, mode, pump_running, soil_value, needs_water)

        conn.close()
        time.sleep(1)

except KeyboardInterrupt:
    print("\nStopped by Ctrl+C")

finally:
    try:
        lgpio.gpio_write(chip, RELAY_PIN, PUMP_OFF_LEVEL)
        time.sleep(0.2)
    except:
        pass
    try:
        lgpio.gpiochip_close(chip)
    except:
        pass
    print("Pump OFF and GPIO closed.")