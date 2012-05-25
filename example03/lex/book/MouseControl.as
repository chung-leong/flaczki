package lex.book 
{
	import flash.display.MovieClip;
	import flash.events.*;
	
	import lex.geom.*;
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class MouseControl
	{
		private var dropPage:DropPage;
		private var fixMouse:FixMouse;
		private var slowing:Slowing;
		private var nav:PageNavigator;
		
		private var mousePlusTr:Boolean;
		
		public var callBackContentRestoreAction:Function;
		public var parkingTr:Boolean;
		private var urgeTr:Boolean;
		public var imBusy:Boolean;
		public var block:Boolean;
		private var startPos:V2 = new V2();
		private var compulsion:Boolean;
		public function MouseControl(dropPage:DropPage,nav:PageNavigator) 
		{
			this.dropPage = dropPage;
			this.nav = nav;
			fixMouse = dropPage.fixMouse;
			slowing = new Slowing(dropPage.s.width / 10);
			slowing.callBack = slowingCall;
		}

		public function addControl():void {
			dropPage.masterSp.addEventListener(MouseEvent.MOUSE_DOWN, masterSpDownHandler);
		}

		public function forceMouseDown(startPos:V2 = null):void {
			if (startPos != null) {
				compulsion = true;
				this.startPos.copy(startPos);
			} else compulsion = false;
			masterSpDownHandler();
		}

		private function masterSpDownHandler(e:MouseEvent = null):void {
			var str:String = '';
			
			if (e != null) {
				str = e.target['name'];
				if (str == 'twoRectSp') return;
				if (str == 'рычаг') return;
				if (str == 'фонСлайдера') return;
				if (str.indexOf('goto', 0) == 0) return;
				
			}
			//trace('mouse action '+str);
			
			if (block) return;
			imBusy = true;
			if (!parkingTr){
				if (dropPage.masterSp.mouseX > 0) {
					mousePlusTr = true;
					nav.aim = nav.cur + 1;
					if (nav.aim > nav.max) return;
					dropPage.dropShow(true,true);
					if (!compulsion) slowing.setCurrent(dropPage.s.width, dropPage.s.height); else
					slowing.setCurrent(startPos.x,startPos.y);
					if (callBackContentRestoreAction != null) callBackContentRestoreAction();
					
				}else {
					mousePlusTr = false;
					nav.aim = nav.cur - 1;
					if (nav.aim < 1) return;
					dropPage.dropShow(true,true);
					
					if (!compulsion) slowing.setCurrent( -dropPage.s.width, dropPage.s.height);else
					slowing.setCurrent(startPos.x, startPos.y);
					
					if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				}
				dropPage.masterSp.stage.addEventListener(MouseEvent.MOUSE_MOVE, masterSpMoveHandler);
				dropPage.masterSp.stage.addEventListener(MouseEvent.MOUSE_UP, masterSpUpHandler);
				masterSpMoveHandler();
			}
			compulsion = false;
		}

		private function masterSpMoveHandler(e:MouseEvent = null):void {
			var coord:V2 = fixMouse.fix(new V2(dropPage.masterSp.mouseX, dropPage.masterSp.mouseY));
			if (!parkingTr) slowing.setCoord(coord.x, coord.y);
			
		}

		private function slowingCall():void {
			if (parkingTr&&slowing.doneTr) {
				if (urgeTr) {
					nav.cur = nav.aim;
					dropPage.dropShow(false,true);
				}else {
					nav.aim = nav.cur;
					dropPage.dropShow(false, true);
				}
				parkingTr = false;
				imBusy = false;
				if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				
			} else
			dropPage.action(slowing.curV2.x, slowing.curV2.y);
		}

		private function masterSpUpHandler(e:MouseEvent):void {
			var tagPlusTr:Boolean = false;
			dropPage.masterSp.stage.removeEventListener(MouseEvent.MOUSE_MOVE, masterSpMoveHandler);
			dropPage.masterSp.stage.removeEventListener(MouseEvent.MOUSE_UP, masterSpUpHandler);
			if (dropPage.masterSp.mouseX > 0) tagPlusTr = true;
			if (tagPlusTr == mousePlusTr) {
				parkingTr = true;
				urgeTr = false;
				if (mousePlusTr) slowing.setCoord(dropPage.s.width, dropPage.s.height);
				if (!mousePlusTr) slowing.setCoord( -dropPage.s.width, dropPage.s.height);
			} else {
				urgeTr = true;
				parkingTr = true;
				if (!mousePlusTr) slowing.setCoord(dropPage.s.width, dropPage.s.height);
				if (mousePlusTr) slowing.setCoord( -dropPage.s.width, dropPage.s.height);
			}
			
		}

	}

}