#!/usr/bin/env python3
"""
Script YOLO para recorte de paneles solares - VERSIÓN PRODUCCIÓN
Supresión TOTAL de mensajes de Ultralytics
"""

import cv2
import numpy as np
import sys
import json
import os
import argparse
import traceback
import warnings
import contextlib
import io

# ✅ SUPRESIÓN AGRESIVA DE MENSAJES
# Suprimir todos los warnings
warnings.filterwarnings('ignore')

# Configurar logging antes de importar Ultralytics
import logging
logging.disable(logging.CRITICAL)

# Variables de entorno para suprimir Ultralytics
os.environ['ULTRALYTICS_SETTINGS'] = 'false'
os.environ['YOLO_VERBOSE'] = 'False'

# Capturar y suprimir stdout temporalmente
@contextlib.contextmanager
def suppress_stdout():
    """Suprimir completamente stdout temporalmente"""
    with open(os.devnull, "w") as devnull:
        old_stdout = sys.stdout
        old_stderr = sys.stderr
        try:
            sys.stdout = devnull
            sys.stderr = devnull
            yield
        finally:
            sys.stdout = old_stdout
            sys.stderr = old_stderr

# ✅ Importar YOLO con supresión completa
with suppress_stdout():
    from ultralytics import YOLO

def order_points(pts):
    """Ordena puntos en orden: top-left, top-right, bottom-right, bottom-left"""
    rect = np.zeros((4, 2), dtype="float32")
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]       # top-left
    rect[2] = pts[np.argmax(s)]       # bottom-right
    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]    # top-right
    rect[3] = pts[np.argmax(diff)]    # bottom-left
    return rect

def load_yolo_model(model_path):
    """Carga el modelo YOLO con supresión total"""
    try:
        if not os.path.exists(model_path):
            raise Exception(f"Modelo no encontrado: {model_path}")

        # ✅ Cargar modelo con supresión total
        with suppress_stdout():
            model = YOLO(model_path, verbose=False)

        print(f"✅ Modelo YOLO cargado: {model_path}", file=sys.stderr)
        return model
    except Exception as e:
        print(f"❌ Error cargando modelo: {e}", file=sys.stderr)
        return None

def detect_panel_with_yolo(model, img, confidence=0.5):
    """Detecta panel usando YOLO"""
    try:
        print("🔍 Ejecutando detección YOLO...", file=sys.stderr)

        # ✅ Hacer predicción con supresión completa
        with suppress_stdout():
            results = model.predict(
                source=img,
                conf=confidence,
                save=False,
                verbose=False,
                show=False
            )

        if not results or len(results) == 0:
            print("❌ No se obtuvieron resultados de YOLO", file=sys.stderr)
            return None, None

        masks = results[0].masks

        if masks is None or masks.data.shape[0] == 0:
            print("❌ No se detectó ninguna máscara", file=sys.stderr)
            return None, None

        print(f"✅ Panel detectado con {len(masks.data)} máscara(s)", file=sys.stderr)

        # Tomar la máscara con mayor confianza (primera)
        mask = masks.data[0].cpu().numpy()
        mask = (mask * 255).astype(np.uint8)

        # Obtener información de confianza
        confidence_score = 0.0
        if hasattr(results[0], 'boxes') and results[0].boxes is not None:
            if len(results[0].boxes.conf) > 0:
                confidence_score = float(results[0].boxes.conf[0])

        print(f"📊 Confianza de detección: {confidence_score:.3f}", file=sys.stderr)

        return mask, confidence_score

    except Exception as e:
        print(f"❌ Error en detección YOLO: {e}", file=sys.stderr)
        return None, None

def extract_panel_contour(mask, img_shape):
    """Extrae contorno del panel desde la máscara YOLO"""
    try:
        print("📐 Extrayendo contorno del panel...", file=sys.stderr)

        # Redimensionar máscara al tamaño de imagen
        img_h, img_w = img_shape[:2]
        mask_resized = cv2.resize(mask, (img_w, img_h))

        # Encontrar contornos
        contours, _ = cv2.findContours(mask_resized, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        if not contours:
            print("❌ No se encontraron contornos en la máscara", file=sys.stderr)
            return None

        # Seleccionar contorno más grande
        largest_contour = max(contours, key=cv2.contourArea)
        area = cv2.contourArea(largest_contour)

        print(f"📏 Área del contorno: {area:.0f} píxeles", file=sys.stderr)

        if area < 1000:  # Área mínima
            print("❌ Contorno muy pequeño", file=sys.stderr)
            return None

        # Aproximar a polígono con diferentes precisiones
        epsilons = [0.01, 0.02, 0.03, 0.05]
        best_approx = None

        for eps in epsilons:
            epsilon = eps * cv2.arcLength(largest_contour, True)
            approx = cv2.approxPolyDP(largest_contour, epsilon, True)

            if len(approx) == 4 and best_approx is None:
                best_approx = approx
                break

        # Usar mejor aproximación o rectángulo mínimo
        if best_approx is not None:
            panel_points = best_approx[:, 0].astype(np.float32)
            print(f"✅ Usando aproximación de 4 puntos", file=sys.stderr)
        else:
            print("⚠️ No se encontró cuadrilátero, usando rectángulo mínimo", file=sys.stderr)
            rect = cv2.minAreaRect(largest_contour)
            box = cv2.boxPoints(rect)
            panel_points = box.astype(np.float32)

        return panel_points

    except Exception as e:
        print(f"❌ Error extrayendo contorno: {e}", file=sys.stderr)
        return None

def apply_perspective_transform(img, points):
    """Aplica transformación de perspectiva al panel"""
    try:
        print("🔄 Aplicando transformación de perspectiva...", file=sys.stderr)

        # Ordenar puntos
        ordered_points = order_points(points)

        # Calcular dimensiones del rectángulo final
        width_top = np.linalg.norm(ordered_points[1] - ordered_points[0])
        width_bottom = np.linalg.norm(ordered_points[2] - ordered_points[3])
        width = int(max(width_top, width_bottom))

        height_left = np.linalg.norm(ordered_points[3] - ordered_points[0])
        height_right = np.linalg.norm(ordered_points[2] - ordered_points[1])
        height = int(max(height_left, height_right))

        print(f"📏 Dimensiones calculadas: {width}x{height}", file=sys.stderr)

        if width <= 0 or height <= 0 or width < 100 or height < 100:
            print(f"❌ Dimensiones inválidas: {width}x{height}", file=sys.stderr)
            return None

        # Puntos de destino
        dst = np.array([
            [0, 0],
            [width - 1, 0],
            [width - 1, height - 1],
            [0, height - 1]
        ], dtype="float32")

        # Calcular matriz de transformación
        M = cv2.getPerspectiveTransform(ordered_points, dst)

        # Aplicar transformación
        warped = cv2.warpPerspective(img, M, (width, height))

        # Verificar que el resultado no esté vacío o muy oscuro
        if warped.size == 0:
            print("❌ Resultado de transformación vacío", file=sys.stderr)
            return None

        gray_warped = cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY)
        mean_brightness = np.mean(gray_warped)

        if mean_brightness < 20:
            print("⚠️ Resultado muy oscuro, normalizando...", file=sys.stderr)
            warped = cv2.normalize(warped, None, 0, 255, cv2.NORM_MINMAX)

        print(f"✅ Transformación exitosa: {width}x{height}", file=sys.stderr)
        return warped

    except Exception as e:
        print(f"❌ Error en transformación: {e}", file=sys.stderr)
        return None

def enhance_image(img):
    """Aplica mejoras básicas a la imagen final"""
    try:
        print("✨ Aplicando mejoras a la imagen...", file=sys.stderr)

        # Convertir a HSV para ajustes
        hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)

        # Ajustar brillo ligeramente
        hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 20)

        # CLAHE para contraste
        clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8))
        hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])

        # Convertir de vuelta
        enhanced = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)

        print("✅ Mejoras aplicadas", file=sys.stderr)
        return enhanced

    except Exception as e:
        print(f"⚠️ Error en mejoras: {e}, usando original", file=sys.stderr)
        return img

def process_image_with_yolo(input_path, output_path, model_path, filas=24, columnas=6, confidence=0.5):
    """Función principal para procesar imagen con YOLO"""
    try:
        print(f"🚀 INICIANDO PROCESAMIENTO YOLO", file=sys.stderr)
        print(f"📂 Input: {input_path}", file=sys.stderr)
        print(f"📂 Output: {output_path}", file=sys.stderr)
        print(f"🤖 Modelo: {model_path}", file=sys.stderr)

        # Verificar archivo de entrada
        if not os.path.exists(input_path):
            raise Exception(f"Archivo de entrada no existe: {input_path}")

        # Cargar modelo YOLO
        model = load_yolo_model(model_path)
        if model is None:
            raise Exception("No se pudo cargar el modelo YOLO")

        # Cargar imagen
        img = cv2.imread(input_path)
        if img is None:
            raise Exception(f"No se pudo cargar la imagen: {input_path}")

        original_shape = img.shape
        print(f"📐 Imagen original: {original_shape[1]}x{original_shape[0]}", file=sys.stderr)

        # Rotación automática si es horizontal
        h, w = img.shape[:2]
        rotated = False
        if w > h:
            img = cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
            rotated = True
            print("🔄 Imagen rotada a vertical", file=sys.stderr)

        # Detectar panel con YOLO
        mask, confidence_score = detect_panel_with_yolo(model, img, confidence)
        if mask is None:
            raise Exception("YOLO no pudo detectar el panel")

        # Extraer contorno del panel
        panel_points = extract_panel_contour(mask, img.shape)
        if panel_points is None:
            raise Exception("No se pudo extraer contorno válido")

        # Aplicar transformación de perspectiva
        warped = apply_perspective_transform(img, panel_points)
        if warped is None:
            raise Exception("Fallo en transformación de perspectiva")

        # Aplicar mejoras
        enhanced = enhance_image(warped)

        # Guardar resultado
        os.makedirs(os.path.dirname(output_path), exist_ok=True)
        success = cv2.imwrite(output_path, enhanced)

        if not success:
            raise Exception(f"Error guardando imagen: {output_path}")

        print(f"💾 Imagen guardada: {output_path}", file=sys.stderr)

        # Calcular métricas
        gray_final = cv2.cvtColor(enhanced, cv2.COLOR_BGR2GRAY)
        non_black = np.count_nonzero(gray_final > 10)
        integridad = round((non_black / gray_final.size) * 100, 2)

        hsv_final = cv2.cvtColor(enhanced, cv2.COLOR_BGR2HSV)
        luminosidad = round(np.mean(hsv_final[:, :, 2]), 2)
        uniformidad = round(np.std(gray_final), 2)

        # Calcular reducción de tamaño
        original_pixels = original_shape[0] * original_shape[1]
        final_pixels = enhanced.shape[0] * enhanced.shape[1]
        reduction = ((original_pixels - final_pixels) / original_pixels) * 100

        # ✅ Resultado exitoso - SOLO JSON EN STDOUT
        result = {
            "success": True,
            "method": "yolo_segmentation",
            "model_path": model_path,
            "confidence": float(confidence_score),
            "integridad": float(integridad),
            "luminosidad": float(luminosidad),
            "uniformidad": float(uniformidad),
            "filas": int(filas),
            "columnas": int(columnas),
            "imagen_rotada": rotated,
            "reduccion_tamaño": f"{reduction:.1f}%",
            "dimensiones_finales": f"{enhanced.shape[1]}x{enhanced.shape[0]}",
            "algorithm_version": "yolo_v8_segmentation",
            "procesamiento_exitoso": True,
            "tipo_imagen": "YOLO_Enhanced"
        }

        print("🎉 PROCESAMIENTO YOLO COMPLETADO EXITOSAMENTE", file=sys.stderr)

        # ✅ CRÍTICO: Solo JSON en stdout, sin texto extra
        print(json.dumps(result, ensure_ascii=True))

    except Exception as e:
        error_msg = str(e)
        print(f"💀 ERROR EN PROCESAMIENTO YOLO: {error_msg}", file=sys.stderr)

        # Resultado de error
        result = {
            "success": False,
            "error": error_msg,
            "method": "yolo_segmentation_failed",
            "traceback": traceback.format_exc()
        }

        # ✅ CRÍTICO: Solo JSON en stdout, incluso en errores
        print(json.dumps(result, ensure_ascii=True))
        sys.exit(1)

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Procesamiento de paneles con YOLO segmentation')
    parser.add_argument('input_path', help='Ruta de la imagen de entrada')
    parser.add_argument('output_path', help='Ruta donde guardar la imagen procesada')
    parser.add_argument('model_path', help='Ruta del modelo YOLO (.pt)')
    parser.add_argument('--filas', type=int, default=24, help='Número de filas del panel')
    parser.add_argument('--columnas', type=int, default=6, help='Número de columnas del panel')
    parser.add_argument('--confidence', type=float, default=0.5, help='Umbral de confianza YOLO')

    args = parser.parse_args()

    process_image_with_yolo(
        args.input_path,
        args.output_path,
        args.model_path,
        args.filas,
        args.columnas,
        args.confidence
    )
