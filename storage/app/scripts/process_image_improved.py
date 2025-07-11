import cv2
import numpy as np
import sys
import json
import traceback
import os
import argparse

def calcular_integridad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    non_black = np.count_nonzero(gray > 30)
    total = gray.size
    return round((non_black / total) * 100, 2)

def calcular_luminosidad(img):
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    brightness = hsv[:, :, 2]
    return round(np.mean(brightness), 5)

def calcular_uniformidad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return round(np.std(gray), 3)

def es_imagen_totalmente_inutilizable(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    mean_val = np.mean(gray)
    std_val = np.std(gray)
    if mean_val < 5 and std_val < 3:
        return True, "Imagen totalmente negra o inutilizable"
    if mean_val > 250 and std_val < 3:
        return True, "Imagen totalmente blanca o sobreexpuesta"
    return False, "Imagen procesable"

def es_imagen_electroluminiscencia(img):
    """Detecta si es una imagen de electroluminiscencia por sus características"""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # Calcular histograma
    hist = cv2.calcHist([gray], [0], None, [256], [0, 256])

    # En imágenes EL, hay mucho negro (valores bajos) y una región brillante
    total_pixels = gray.size
    black_pixels = np.sum(hist[0:50])  # Píxeles muy oscuros
    bright_pixels = np.sum(hist[150:256])  # Píxeles brillantes

    black_ratio = black_pixels / total_pixels
    bright_ratio = bright_pixels / total_pixels

    # Si >60% es negro y >10% es brillante, probablemente es EL
    if black_ratio > 0.6 and bright_ratio > 0.1:
        return True

    return False

def detectar_panel_EL_avanzado(img):
    """Estrategia especializada para imágenes de electroluminiscencia"""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # 1. Umbralización adaptativa más agresiva
    # Usar un umbral más alto para separar bien el panel del fondo
    _, binary = cv2.threshold(gray, 50, 255, cv2.THRESH_BINARY)

    # 2. Operaciones morfológicas para limpiar ruido
    kernel = np.ones((7, 7), np.uint8)
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)
    binary = cv2.morphologyEx(binary, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))

    # 3. Rellenar huecos en el interior del panel
    kernel_fill = np.ones((15, 15), np.uint8)
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel_fill)

    # 4. Encontrar contornos
    contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    if not contours:
        return None, binary

    # 5. Filtrar contornos por área (más permisivo para EL)
    height, width = img.shape[:2]
    area_total = height * width

    contornos_validos = []
    for cnt in contours:
        area = cv2.contourArea(cnt)
        # Para imágenes EL, el panel puede ser relativamente pequeño en la imagen
        if area > 0.01 * area_total:  # Reducido de 0.02 a 0.01
            # Verificar que no sea demasiado alargado o estrecho
            x, y, w, h = cv2.boundingRect(cnt)
            aspect_ratio = max(w, h) / min(w, h)
            if aspect_ratio < 5:  # No demasiado alargado
                contornos_validos.append(cnt)

    if not contornos_validos:
        return None, binary

    # 6. Seleccionar el contorno más grande
    panel_contour = max(contornos_validos, key=cv2.contourArea)

    return panel_contour, binary

def refinar_contorno_panel(contour, img_shape):
    """Refina el contorno del panel para obtener un rectángulo más preciso"""
    # Usar minAreaRect para obtener el rectángulo rotado mínimo
    rect = cv2.minAreaRect(contour)
    box = cv2.boxPoints(rect).astype(np.int32)

    # Verificar si el rectángulo es razonable
    width = rect[1][0]
    height = rect[1][1]

    if width < 50 or height < 50:
        # Si es muy pequeño, usar bounding rect
        x, y, w, h = cv2.boundingRect(contour)
        box = np.array([[x, y], [x+w, y], [x+w, y+h], [x, y+h]], dtype=np.int32)

    return box

def order_points(pts):
    rect = np.zeros((4, 2), dtype="float32")
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]
    rect[2] = pts[np.argmax(s)]
    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]
    rect[3] = pts[np.argmax(diff)]
    return rect

def recorte_razonable(warped, original_shape):
    h, w = warped.shape[:2]
    H, W = original_shape[:2]

    if w < 100 or h < 100:
        return False  # Demasiado pequeño

    area_ratio = (h * w) / (H * W)
    if area_ratio < 0.05:  # Reducido de 0.2 a 0.05 para EL
        return False  # Panel muy pequeño

    aspect_ratio = h / w
    if aspect_ratio < 0.3 or aspect_ratio > 4.0:  # Más permisivo
        return False  # Proporción rara

    return True

def estrategia_recorte_directo_EL(img):
    """Estrategia de recorte directo optimizada para imágenes EL"""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # Umbral más alto para EL
    _, binary = cv2.threshold(gray, 40, 255, cv2.THRESH_BINARY)

    # Operaciones morfológicas para conectar regiones del panel
    kernel = np.ones((10, 10), np.uint8)
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)

    # Encontrar todos los píxeles blancos
    y_coords, x_coords = np.where(binary > 0)

    if len(y_coords) < 1000:  # Aumentado el umbral mínimo
        return None

    # Obtener rectángulo que englobe toda la región brillante
    x_min, x_max = np.min(x_coords), np.max(x_coords)
    y_min, y_max = np.min(y_coords), np.max(y_coords)

    # Añadir margen más pequeño para EL
    margin = max(10, min(img.shape[0], img.shape[1]) // 100)
    x_min = max(0, x_min - margin)
    y_min = max(0, y_min - margin)
    x_max = min(img.shape[1] - 1, x_max + margin)
    y_max = min(img.shape[0] - 1, y_max + margin)

    # Verificar que el recorte sea razonable
    w, h = x_max - x_min, y_max - y_min
    if w < 100 or h < 100:
        return None

    return img[y_min:y_max, x_min:x_max]

def process_image(input_path, output_path, filas=10, columnas=6):
    # Leer la imagen original
    img = cv2.imread(input_path)
    if img is None:
        raise Exception(f"No se pudo cargar la imagen: {input_path}")

    # 👉 ROTACIÓN AUTOMÁTICA si es horizontal
    h, w = img.shape[:2]
    if w > h:
        img = cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)

    # Verificar si la imagen es procesable
    es_inutilizable, mensaje = es_imagen_totalmente_inutilizable(img)
    if es_inutilizable:
        raise Exception(f"Imagen no procesable: {mensaje}")

    # 🔍 DETECCIÓN DE TIPO DE IMAGEN
    es_EL = es_imagen_electroluminiscencia(img)
    print(f"Imagen detectada como EL: {es_EL}", file=sys.stderr)

    warped = None

    # ESTRATEGIA ESPECÍFICA PARA ELECTROLUMINISCENCIA
    if es_EL:
        try:
            print("Aplicando estrategia EL especializada...", file=sys.stderr)

            # Método 1: Detección avanzada de contornos para EL
            contour, binary = detectar_panel_EL_avanzado(img)

            if contour is not None:
                # Refinar el contorno
                box = refinar_contorno_panel(contour, img.shape)
                pts = order_points(box.reshape(4, 2).astype(np.float32))

                # Calcular dimensiones del rectángulo
                width_a = np.linalg.norm(pts[1] - pts[0])
                width_b = np.linalg.norm(pts[2] - pts[3])
                width = max(int(width_a), int(width_b))

                height_a = np.linalg.norm(pts[3] - pts[0])
                height_b = np.linalg.norm(pts[2] - pts[1])
                height = max(int(height_a), int(height_b))

                # Ajustar proporción si es necesario
                if width / height < 0.4:
                    width = int(height * 0.4)
                elif width / height > 3.0:
                    height = int(width / 3.0)

                # Transformación de perspectiva
                dst = np.array([[0, 0], [width-1, 0], [width-1, height-1], [0, height-1]], dtype="float32")
                M = cv2.getPerspectiveTransform(pts, dst)
                warped = cv2.warpPerspective(img, M, (width, height))

                print("✅ Estrategia EL con contornos exitosa", file=sys.stderr)
            else:
                raise Exception("No se detectó contorno válido")

        except Exception as e:
            print(f"⚠️ Estrategia EL con contornos falló: {e}", file=sys.stderr)
            try:
                # Método 2: Recorte directo para EL
                print("Aplicando recorte directo EL...", file=sys.stderr)
                warped = estrategia_recorte_directo_EL(img)

                if warped is not None:
                    print("✅ Estrategia recorte directo EL exitosa", file=sys.stderr)
                else:
                    raise Exception("Recorte directo EL falló")

            except Exception as e2:
                print(f"❌ Todas las estrategias EL fallaron: {e2}", file=sys.stderr)
                raise Exception("No se pudo procesar la imagen EL")

    # ESTRATEGIAS ORIGINALES para imágenes no-EL
    else:
        try:
            # [Mantener las estrategias originales 1, 2 y 3 del código original]
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            thresh = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                          cv2.THRESH_BINARY, 11, 2)
            thresh = cv2.bitwise_not(thresh)
            thresh = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))

            contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

            height, width = img.shape[:2]
            area_total = height * width
            contornos_validos = [
                cnt for cnt in contours
                if cv2.contourArea(cnt) > 0.05 * area_total
            ]

            if not contornos_validos:
                raise Exception("Método 1 falló: No se encontró un contorno válido del panel")

            panel_contour = max(contornos_validos, key=cv2.contourArea)
            epsilon = 0.02 * cv2.arcLength(panel_contour, True)
            approx = cv2.approxPolyDP(panel_contour, epsilon, True)

            if len(approx) > 4:
                box = cv2.boxPoints(cv2.minAreaRect(approx)).astype(np.int32)
                approx = box.reshape(-1, 1, 2)
            elif len(approx) < 4:
                x, y, w, h = cv2.boundingRect(panel_contour)
                approx = np.array([[[x, y]], [[x+w, y]], [[x+w, y+h]], [[x, y+h]]])

            pts = order_points(approx.reshape(len(approx), 2))
            width = int(max(np.linalg.norm(pts[1] - pts[0]), np.linalg.norm(pts[2] - pts[3])))
            height = int(max(np.linalg.norm(pts[3] - pts[0]), np.linalg.norm(pts[2] - pts[1])))

            if width / height < 0.5:
                width = int(height * 0.5)
            elif width / height > 2.0:
                height = int(width / 2.0)

            dst = np.array([[0, 0], [width-1, 0], [width-1, height-1], [0, height-1]], dtype="float32")
            M = cv2.getPerspectiveTransform(pts, dst)
            warped = cv2.warpPerspective(img, M, (width, height))

        except Exception as e:
            # [Mantener estrategias 2 y 3 del código original]
            print(f"Métodos tradicionales fallaron, usando fallback: {e}", file=sys.stderr)
            warped = estrategia_recorte_directo_EL(img)  # Usar como fallback

    # Verificar que el recorte sea razonable
    if warped is None or not recorte_razonable(warped, img.shape):
        raise Exception("No se pudo obtener un recorte válido del panel")

    # Mejorar la imagen resultante (especialmente importante para EL)
    if es_EL:
        # Para imágenes EL, aplicar mejoras más suaves
        hsv = cv2.cvtColor(warped, cv2.COLOR_BGR2HSV)
        hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 20)  # Brillo más suave
        clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8))  # CLAHE más suave
        hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])
        result = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)
    else:
        # Para imágenes normales, usar el procesamiento original
        hsv = cv2.cvtColor(warped, cv2.COLOR_BGR2HSV)
        hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 30)
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])
        result = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)

    # Guardar y devolver resultados
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    cv2.imwrite(output_path, result)

    # Calcular métricas del panel ya recortado
    integridad = calcular_integridad(warped)
    luminosidad = calcular_luminosidad(warped)
    uniformidad = calcular_uniformidad(warped)

    result_dict = {
        "integridad": float(integridad),
        "luminosidad": float(luminosidad),
        "uniformidad": float(uniformidad),
        "filas": int(filas),
        "columnas": int(columnas),
        "microgrietas": 0,
        "fingers": 0,
        "black_edges": 0,
        "intensidad": 0,
        "tipo_imagen": "EL" if es_EL else "Normal"
    }

    print(json.dumps(result_dict, ensure_ascii=True))

if __name__ == "__main__":
    try:
        parser = argparse.ArgumentParser(description='Procesar imagen de panel solar')
        parser.add_argument('input_path', help='Ruta de la imagen de entrada')
        parser.add_argument('output_path', help='Ruta donde guardar la imagen procesada')
        parser.add_argument('--filas', type=int, default=10)
        parser.add_argument('--columnas', type=int, default=6)
        args = parser.parse_args()
        process_image(args.input_path, args.output_path, args.filas, args.columnas)
    except Exception as e:
        print(json.dumps({"error": str(e)}, ensure_ascii=True))
        traceback.print_exc()
        sys.exit(1)
